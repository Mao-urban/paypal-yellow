<?php
class YellowPayPalDonate {
    const VERSION = "3.0.0";

    private $yellow;
    private $db;

    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $yellow->plugins->register("paypaldonate", __CLASS__, self::VERSION);

        $this->initDatabase();
    }

    // 🔹 Load config
    private function getConfig($key) {
        return $this->yellow->config->get($key);
    }

    // 🗄️ Init SQLite DB
    private function initDatabase() {
        $path = __DIR__ . "/paypaldonate.db";

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
            return $this->renderDonateBox();
        }
    }

    // 🎨 UI
    private function renderDonateBox() {
        $clientId = $this->getConfig("paypalClientId");
        $currency = $this->getConfig("paypalCurrency");

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

                return fetch("/paypal-api/create-order", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ amount: amount })
                })
                .then(res => res.json())
                .then(data => data.id);
            },

            onApprove: function(data) {
                return fetch("/paypal-api/capture-order", {
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

    // 🌐 Routing
    public function onParseRequest($scheme, $host, $base, $location, $fileName, $args) {

        if (strpos($location, "paypal-api") === 0) {

            header("Content-Type: application/json");
            $input = json_decode(file_get_contents("php://input"), true);

            if ($location == "paypal-api/create-order") {
                echo $this->createOrder($input);
                return true;
            }

            if ($location == "paypal-api/capture-order") {
                echo $this->captureOrder($input);
                return true;
            }
        }
    }

    // 🔐 Token
    private function getAccessToken() {
        $clientId = $this->getConfig("paypalClientId");
        $secret = $this->getConfig("paypalSecret");
        $mode = $this->getConfig("paypalMode");

        $baseUrl = $mode == "live"
            ? "https://api-m.paypal.com"
            : "https://api-m.sandbox.paypal.com";

        $ch = curl_init($baseUrl."/v1/oauth2/token");

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$clientId:$secret");
        curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: application/json",
            "Accept-Language: en_US"
        ]);

        $response = json_decode(curl_exec($ch), true);

        return [$response["access_token"], $baseUrl];
    }

    // 💰 Create Order
    private function createOrder($input) {

        $amount = floatval($input["amount"] ?? 0);

        if ($amount < 1) {
            return json_encode(["error" => "Minimum amount is 1"]);
        }

        list($token, $baseUrl) = $this->getAccessToken();

        $currency = $this->getConfig("paypalCurrency");

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

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer ".$token
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

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer ".$token
        ]);

        $result = json_decode(curl_exec($ch), true);

        // 💾 Save to DB
        if (isset($result["status"]) && $result["status"] == "COMPLETED") {

            $payer = $result["payer"];
            $amount = $result["purchase_units"][0]["payments"]["captures"][0]["amount"]["value"];
            $currency = $result["purchase_units"][0]["payments"]["captures"][0]["amount"]["currency_code"];

            $stmt = $this->db->prepare("
                INSERT INTO donations (order_id, payer_name, payer_email, amount, currency)
                VALUES (:order_id, :name, :email, :amount, :currency)
            ");

            $stmt->bindValue(":order_id", $orderID);
            $stmt->bindValue(":name", $payer["name"]["given_name"]);
            $stmt->bindValue(":email", $payer["email_address"]);
            $stmt->bindValue(":amount", $amount);
            $stmt->bindValue(":currency", $currency);

            $stmt->execute();
        }

        return json_encode($result);
    }
}
