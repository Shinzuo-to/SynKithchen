<?php

header("Content-Type: application/json");
require_once __DIR__ . "/id-verification.php";

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new Exception("Use POST to verify an ID.");
    }

    $firstName = trim($_POST["first_name"] ?? "");
    $middleName = trim($_POST["middle_name"] ?? "");
    $lastName = trim($_POST["last_name"] ?? "");
    $idType = trim($_POST["id_type"] ?? "");

    if ($firstName === "" || $lastName === "") {
        throw new Exception("First name and last name are required.");
    }

    validateIdType($idType);

    if (!isset($_FILES["id_image"])) {
        throw new Exception("Upload an image of your ID.");
    }

    validateImageUpload($_FILES["id_image"]);
    $ocrImage = $_FILES["id_image_ocr"] ?? $_FILES["id_image"];
    $ocrMimeType = validateImageUpload($ocrImage);
    verifyIdDocument(
        $ocrImage["tmp_name"],
        $ocrMimeType,
        $idType,
        $firstName,
        $lastName,
        $middleName
    );

    $receipt = createIdVerificationReceipt(
        $_FILES["id_image"]["tmp_name"],
        $firstName,
        $middleName,
        $lastName,
        $idType
    );
    $verificationToken = createIdVerificationReceiptToken($receipt);

    echo json_encode([
        "success" => true,
        "message" => "The ID text matches the selected type and entered name. The document's authenticity has not been verified.",
        "verification_token" => $verificationToken
    ]);
} catch (Exception $error) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => $error->getMessage()
    ]);
}
?>
