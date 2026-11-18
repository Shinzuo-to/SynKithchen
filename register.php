<?php

header("Content-Type: application/json");
require_once __DIR__ . "/id-verification.php";

$response = [
    "success" => false,
    "message" => "Unknown error."
];
$storedPhotoPaths = [];
$registrationSaved = false;

try {

    /*
    |--------------------------------------------------------------------------
    | DATABASE CONNECTION
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | API KEYS
    |--------------------------------------------------------------------------
    */

    define(
        "FACEPP_API_KEY",
        "PIXg13pJYA5jPc8gGtlBFd2mg1yQuaaE"
    );

    define(
        "FACEPP_API_SECRET",
        "5fFn76kUCjZ1Cr0By4s9YN4R7nuNwe4Z"
    );

    /*
    |--------------------------------------------------------------------------
    | INPUT VALIDATION
    |--------------------------------------------------------------------------
    */

    $firstName =
        trim($_POST["first_name"] ?? "");

    $lastName =
        trim($_POST["last_name"] ?? "");

    $middleName =
        trim($_POST["middle_name"] ?? "");

    $username =
        trim($_POST["username"] ?? "");

    $password =
        $_POST["password"] ?? "";
    $idType =
        trim($_POST["id_type"] ?? "");

    if (
        empty($firstName) ||
        empty($lastName) ||
        empty($username) ||
        empty($password)
    ) {
        throw new Exception(
            "All fields are required."
        );
    }

    validateIdType($idType);

    if (
        !isset($_FILES["id_image"]) ||
        !isset($_FILES["selfie"])
    ) {
        throw new Exception(
            "ID image and selfie are required."
        );
    }

    $idMimeType =
        validateImageUpload($_FILES["id_image"]);

    $selfieMimeType =
        validateImageUpload($_FILES["selfie"]);
    $idMatchFile =
        $_FILES["id_image_match"] ?? $_FILES["id_image"];
    $selfieMatchFile =
        $_FILES["selfie_match"] ?? $_FILES["selfie"];
    $idMatchMimeType =
        validateImageUpload($idMatchFile);
    $selfieMatchMimeType =
        validateImageUpload($selfieMatchFile);

    $receiptToken =
        trim($_POST["id_verification_token"] ?? "");
    $receipt =
        readIdVerificationReceiptToken($receiptToken);

    if (!matchesIdVerificationReceipt(
        $receipt,
        $_FILES["id_image"]["tmp_name"],
        $firstName,
        $middleName,
        $lastName,
        $idType
    )) {
        throw new Exception("Your ID check has expired or the details changed. Please check your ID again.");
    }

    consumeIdVerificationReceiptToken($receiptToken);

    $conn = new mysqli(
        "localhost",
        "root",
        "",
        "the_burgerhotdog"
    );

    if ($conn->connect_error) {
        throw new Exception(
            "Database connection failed."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK USERNAME
    |--------------------------------------------------------------------------
    */

    $check =
        $conn->prepare(
            "SELECT id
             FROM users
             WHERE username = ?"
        );

    $check->bind_param(
        "s",
        $username
    );

    $check->execute();

    $check->store_result();

    if ($check->num_rows > 0) {

        throw new Exception(
            "Username already exists."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE UPLOAD DIRECTORY
    |--------------------------------------------------------------------------
    */

    $uploadDir =
        dirname(dirname(__DIR__)) .
        DIRECTORY_SEPARATOR .
        "SynKitchenUploads" .
        DIRECTORY_SEPARATOR;

    if (
        !is_dir($uploadDir) &&
        !mkdir($uploadDir, 0750, true) &&
        !is_dir($uploadDir)
    ) {
        throw new Exception("Could not create the user photo storage directory.");
    }

    /*
    |--------------------------------------------------------------------------
    | PREPARE PHOTO STORAGE PATHS
    |--------------------------------------------------------------------------
    */

    $idFileName =
        uniqid("id_", true) .
        "." .
        imageExtensionForMime($idMimeType);

    $selfieFileName =
        uniqid("selfie_", true) .
        "." .
        imageExtensionForMime($selfieMimeType);

    $idPath = $uploadDir . $idFileName;
    $selfiePath = $uploadDir . $selfieFileName;
    $idDatabasePath = "../../SynKitchenUploads/" . $idFileName;
    $selfieDatabasePath = "../../SynKitchenUploads/" . $selfieFileName;

    /*
    |--------------------------------------------------------------------------
    | FACE++ VERIFICATION
    |--------------------------------------------------------------------------
    */

    $faceCurl =
        curl_init();

    curl_setopt_array(
        $faceCurl,
        [
            CURLOPT_URL =>
                "https://api-us.faceplusplus.com/facepp/v3/compare",

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_POST =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                10,

            CURLOPT_TIMEOUT =>
                45,

            CURLOPT_POSTFIELDS =>
                [
                    "api_key" =>
                        FACEPP_API_KEY,

                    "api_secret" =>
                        FACEPP_API_SECRET,

                    "image_file1" =>
                        new CURLFile(
                            $idMatchFile["tmp_name"],
                            $idMatchMimeType,
                            "id-match." . imageExtensionForMime($idMatchMimeType)
                        ),

                    "image_file2" =>
                        new CURLFile(
                            $selfieMatchFile["tmp_name"],
                            $selfieMatchMimeType,
                            "selfie-match." . imageExtensionForMime($selfieMatchMimeType)
                        )
                ]
        ]
    );

    $faceResponse =
        curl_exec(
            $faceCurl
        );

    if (
        $faceResponse === false
    ) {
        $faceError = curl_error($faceCurl);
        curl_close($faceCurl);
        throw new Exception(
            "Face++ Error: " .
            $faceError
        );
    }

    $faceHttpStatus =
        curl_getinfo(
            $faceCurl,
            CURLINFO_HTTP_CODE
        );

    curl_close($faceCurl);

    if (
        $faceHttpStatus < 200 ||
        $faceHttpStatus >= 300
    ) {
        throw new Exception(
            "Face verification service returned HTTP " .
            $faceHttpStatus .
            ". Please try again."
        );
    }

    $faceData =
        json_decode(
            $faceResponse,
            true
        );

    if (
        json_last_error() !== JSON_ERROR_NONE ||
        !is_array($faceData) ||
        !isset($faceData["confidence"]) ||
        !is_numeric($faceData["confidence"])
    ) {

        $errorMessage = "Face verification did not return a valid match result.";
        if (
            is_array($faceData) &&
            isset($faceData["error_message"]) &&
            is_string($faceData["error_message"]) &&
            trim($faceData["error_message"]) !== ""
        ) {
            $errorMessage = trim($faceData["error_message"]);
        }

        throw new Exception(
            $errorMessage
        );
    }

    $confidence =
        floatval(
            $faceData["confidence"]
        );

    if (
        !is_finite($confidence) ||
        $confidence < 0 ||
        $confidence > 100
    ) {
        throw new Exception(
            "Face verification returned an invalid similarity score. Please try again."
        );
    }

    if (
        $confidence < 70
    ) {

        throw new Exception(
            "Face does not match uploaded ID. Similarity score: "
            . round(
                $confidence,
                2
            )
            . "%"
        );
    }

    if (
        !move_uploaded_file(
            $_FILES["id_image"]["tmp_name"],
            $idPath
        )
    ) {
        throw new Exception(
            "The faces matched, but the ID image could not be saved."
        );
    }
    $storedPhotoPaths[] = $idPath;

    if (
        !move_uploaded_file(
            $_FILES["selfie"]["tmp_name"],
            $selfiePath
        )
    ) {
        throw new Exception(
            "The faces matched, but the selfie could not be saved."
        );
    }
    $storedPhotoPaths[] = $selfiePath;

    /*
    |--------------------------------------------------------------------------
    | SAVE USER
    |--------------------------------------------------------------------------
    */

    $hashedPassword =
        password_hash(
            $password,
            PASSWORD_DEFAULT
        );

    $stmt =
        $conn->prepare(
            "INSERT INTO users
            (
                first_name,
                middle_name,
                last_name,
                id_type,
                username,
                password,
                id_image,
                selfie_image,
                face_confidence
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?
            )"
        );

    $stmt->bind_param(
        "ssssssssd",
        $firstName,
        $middleName,
        $lastName,
        $idType,
        $username,
        $hashedPassword,
        $idDatabasePath,
        $selfieDatabasePath,
        $confidence
    );

    if (
        !$stmt->execute()
    ) {
        throw new Exception(
            "Unable to register user."
        );
    }
    $registrationSaved = true;

    $response = [
        "success" => true,
        "message" =>
            "Registration successful."
    ];

}
catch (Exception $e) {

    if (!$registrationSaved) {
        foreach ($storedPhotoPaths as $storedPhotoPath) {
            if (is_file($storedPhotoPath) && !unlink($storedPhotoPath)) {
                error_log("Failed to remove an unregistered user photo.");
            }
        }
    }

    $response = [
        "success" => false,
        "message" => $e->getMessage()
    ];
}

echo json_encode(
    $response
);
?>