<?php
class YellowPayPalDonate {
    const VERSION = "4.0.0";

    private $yellow;
    private $db;

    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $yellow->plugins->register("paypaldonate", __CLASS__, self::VERSION);
        $this->initDatabase();
    }

    // 🗄️ Safe DB init (Yellow-compliant path)
    private function initDatabase() {
        $path = $this->yellow->config->get("coreServerBase") . "/system/cache/paypaldonate.db";
        $this->db = new SQLite3($path);

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS donations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT,
                payer_name TEXT,
                payer_email TEXT,
                amount REAL,
                currency TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    // 🔹 Shortcode
    public function onParseContentElement($page, $name, $text, $attributes) {
        if ($name == "paypalDonate") {
            return $this->renderUI();
        }
    }

    // 🎨 Frontend (base-path safe)
    private function renderUI() {
        $clientId = $this->yellow->config->get("paypaldonateClientId");
        $currency = $this->yellow->config->get("paypaldonateCurrency");
        $base = $this->yellow->config->get("coreServerBase");

        return '
        <div style="max-width:300px;">
            <input type="number" id="donation-amount" placeholder="Enter amount" min="1" style="width:100%; padding:10px; margin-bottom:10px;">
            <div id="paypal-button-container"></div>
        </div>

        <script src="https://www.paypal.com/sdk/js?client-id='.$clientId.'&currency='.$currency.'"></script>

        <script>
        paypal.Buttons({

            createOrder: function() {
                let amount = document.getElementById("donation-amount").value;

                if (!amount || amount <= 0) {
                    alert("Enter valid amount");
                    return;
                }

                return fetch("'.$base.'/paypal-api/create-order", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ amount: amount })
                })
                .then(res => res.json())
                .then(data => data.id);
            },

            onApprove: function(data) {
                return fetch("'.$base.'/paypal-api/capture-order", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ orderID: data.orderID })
                })
                .then(res => res.json())
                .then(details => {
                    alert("Thank you " + details.payer.name.given_name);
                });
            }

        }).render("#paypal-button-container");
        </script>';
    }

    // 🌐 Routing (Yellow-native)
    public function onParseRequest($scheme, $host, $base, $location, $fileName, $args) {

        if (strpos($location, "paypal-api") === 0) {

            header("Content-Type: application/json");

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(["error" => "Invalid request"]);
                exit;
            }

            $input = json_decode(file_get_contents("php://input"), true);

            if ($location == "paypal-api/create-order") {
                echo $this->createOrder($input);
                exit;
            }

            if ($location == "paypal-api/capture-order") {
                echo $this->captureOrder($input);
                exit;
            }
        }
    }

    // 🔐 Token
    private function getAccessToken() {
        $clientId = $this->yellow->config->get("paypaldonateClientId");
        $secret = $this->yellow->config->get("paypaldonateSecret");
        $mode = $this->yellow->config->get("paypaldonateMode");

        $baseUrl = $mode === "live"
            ? "https://api-m.paypal.com"
            : "https://api-m.sandbox.paypal.com";

        $ch = curl_init($baseUrl."/v1/oauth2/token");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "$clientId:$secret",
            CURLOPT_POSTFIELDS => "grant_type=client_credentials",
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                "Accept-Language: en_US"
            ]
        ]);

        $response = json_decode(curl_exec($ch), true);

        return [$response["access_token"] ?? null, $baseUrl];
    }

    // 💰 Create Order
    private function createOrder($input) {

        $amount = floatval($input["amount"] ?? 0);
        $min = floatval($this->yellow->config->get("paypaldonateMinAmount"));
        $max = floatval($this->yellow->config->get("paypaldonateMaxAmount"));

        if ($amount < $min || $amount > $max) {
            return json_encode(["error" => "Invalid amount"]);
        }

        list($token, $baseUrl) = $this->getAccessToken();

        if (!$token) {
            return json_encode(["error" => "Auth failed"]);
        }

        $currency = $this->yellow->config->get("paypaldonateCurrency");

        $data = [
            "intent" => "CAPTURE",
            "purchase_units" => [[
                "amount" => [
                    "currency_code" => $currency,
                    "value" => number_format($amount, 2, '.', '')
                ]
            ]]
        ];

        $ch = curl_init($baseUrl."/v2/checkout/orders");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer ".$token
            ]
        ]);

        return curl_exec($ch);
    }

    // ✅ Capture + Store
    private function captureOrder($input) {

        $orderID = $input["orderID"] ?? "";

        if (!$orderID) {
            return json_encode(["error" => "Missing orderID"]);
        }

        list($token, $baseUrl) = $this->getAccessToken();

        $ch = curl_init($baseUrl."/v2/checkout/orders/".$orderID."/capture");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer ".$token
            ]
        ]);

        $result = json_decode(curl_exec($ch), true);

        // 💾 Store if completed
        if (($result["status"] ?? "") === "COMPLETED") {

            $payer = $result["payer"];
            $capture = $result["purchase_units"][0]["payments"]["captures"][0];

            $stmt = $this->db->prepare("
                INSERT INTO donations (order_id, payer_name, payer_email, amount, currency)
                VALUES (:order_id, :name, :email, :amount, :currency)
            ");

            $stmt->bindValue(":order_id", $orderID);
            $stmt->bindValue(":name", $payer["name"]["given_name"] ?? "");
            $stmt->bindValue(":email", $payer["email_address"] ?? "");
            $stmt->bindValue(":amount", $capture["amount"]["value"] ?? 0);
            $stmt->bindValue(":currency", $capture["amount"]["currency_code"] ?? "");

            $stmt->execute();
        }

        return json_encode($result);
    }
}
