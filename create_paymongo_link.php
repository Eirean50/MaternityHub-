<?php
// create_paymongo_link.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $amount = $_POST['amount'] ?? 0;
    $description = $_POST['description'] ?? 'Maternity Hub Appointment';

    // PayMongo requires centavos
    $amount_in_centavos = (int)round($amount * 100);

    // ✨ PASTE YOUR GROUPMATE'S SECRET KEY HERE ✨
    $paymongo_secret_key = 'secret';

    $ch = curl_init();
    // 🌟 UPGRADED to checkout_sessions
    curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    $payload = json_encode([
        "data" => [
            "attributes" => [
                "send_email_receipt" => false,
                "show_description" => true,
                "show_line_items" => true,
                // 🌟 THE MAGIC REDIRECT LINKS!
                "success_url" => "maternityhub://payment/success",
                "cancel_url" => "maternityhub://payment/cancel",
                "payment_method_types" => ["gcash", "paymaya", "card"],
                "line_items" => [
                    [
                        "currency" => "PHP",
                        "amount" => $amount_in_centavos,
                        "name" => $description,
                        "quantity" => 1
                    ]
                ]
            ]
        ]
    ]);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($paymongo_secret_key . ':')
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpcode == 200 && isset($result['data']['attributes']['checkout_url'])) {
        echo json_encode([
            "status" => "success",
            "checkout_url" => $result['data']['attributes']['checkout_url']
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "PayMongo failed to create session."]);
    }

} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
?>