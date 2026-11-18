<?php

session_start();

header("Content-Type: application/json");

$response = [
    "success" => false,
    "message" => "Login failed."
];

try {

    if (!session_write_close()) {
        throw new Exception(
            "Could not start login verification."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DATABASE
    |--------------------------------------------------------------------------
    */

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
    | FACE++ CONFIG
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
    | VALIDATE INPUT
    |--------------------------------------------------------------------------
    */

    $username =
        trim($_POST["username"] ?? "");

    $password =
        $_POST["password"] ?? "";

    if (
        empty($username) ||
        empty($password)
    ) {
        throw new Exception(
            "Username and password are required."
        );
    }

    if (!isset($_FILES["selfie"])) {
        throw new Exception(
            "Selfie is required."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GET USER
    |--------------------------------------------------------------------------
    */

    $stmt =
        $conn->prepare(
            "SELECT
                id,
                first_name,
                middle_name,
                last_name,
                id_type,
                username,
                password,
                selfie_image
            FROM users
            WHERE username = ?"
        );

    $stmt->bind_param(
        "s",
        $username
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception(
            "Invalid username or password."
        );
    }

    $user =
        $result->fetch_assoc();

    /*
    |--------------------------------------------------------------------------
    | VERIFY PASSWORD
    |--------------------------------------------------------------------------
    */

    if (
        !password_verify(
            $password,
            $user["password"]
        )
    ) {
        throw new Exception(
            "Invalid username or password."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE LOGIN SELFIE
    |--------------------------------------------------------------------------
    */

    $uploadDir =
        dirname(dirname(__DIR__)) .
        DIRECTORY_SEPARATOR .
        "SynKitchenUploads" .
        DIRECTORY_SEPARATOR .
        "login" .
        DIRECTORY_SEPARATOR;

    if (!is_dir($uploadDir)) {
        mkdir(
            $uploadDir,
            0777,
            true
        );
    }

    $loginSelfiePath =
        $uploadDir .
        uniqid("login_") .
        ".jpg";

    if (
        !move_uploaded_file(
            $_FILES["selfie"]["tmp_name"],
            $loginSelfiePath
        )
    ) {
        throw new Exception(
            "Failed to save selfie."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTERED SELFIE
    |--------------------------------------------------------------------------
    */

    $registeredImage =
        $user["selfie_image"];

    $resolvedRegisteredImage =
        realpath($registeredImage);

    if ($resolvedRegisteredImage === false) {
        $resolvedRegisteredImage =
            realpath(
                __DIR__ .
                DIRECTORY_SEPARATOR .
                $registeredImage
            );
    }

    if (
        $resolvedRegisteredImage === false
    ) {
        throw new Exception(
            "Registered face image not found."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FACE++ COMPARE
    |--------------------------------------------------------------------------
    */

    $faceCurl =
        curl_init();

    curl_setopt_array(
        $faceCurl,
        [
            CURLOPT_URL =>
                "https://api-us.faceplusplus.com/facepp/v3/compare",

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_POST => true,

            CURLOPT_CONNECTTIMEOUT => 10,

            CURLOPT_TIMEOUT => 45,

            CURLOPT_POSTFIELDS => [

                "api_key" =>
                    FACEPP_API_KEY,

                "api_secret" =>
                    FACEPP_API_SECRET,

                "image_file1" =>
                    new CURLFile(
                        $resolvedRegisteredImage
                    ),

                "image_file2" =>
                    new CURLFile(
                        realpath($loginSelfiePath)
                    )
            ]
        ]
    );

    $faceResponse =
        curl_exec(
            $faceCurl
        );

    if ($faceResponse === false) {
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

    /*
    |--------------------------------------------------------------------------
    | CHECK RESPONSE
    |--------------------------------------------------------------------------
    */

    if (
        !isset(
            $faceData["confidence"]
        )
    ) {

        $apiMessage =
            $faceData["error_message"]
            ?? "Face comparison failed.";

        throw new Exception(
            $apiMessage
        );
    }

    $confidence =
        floatval(
            $faceData["confidence"]
        );

    /*
    |--------------------------------------------------------------------------
    | CONFIDENCE THRESHOLD
    |--------------------------------------------------------------------------
    */

    $requiredConfidence = 70;

    if (
        $confidence <
        $requiredConfidence
    ) {

        throw new Exception(
            "Face verification failed. Similarity score: "
            . round(
                $confidence,
                2
            ) .
            "%"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LOGIN SUCCESS
    |--------------------------------------------------------------------------
    */

    if (!session_start()) {
        throw new Exception(
            "Could not save the login session."
        );
    }

    $_SESSION["user_id"] =
        $user["id"];

    $_SESSION["username"] =
        $user["username"];

    $_SESSION["first_name"] =
        $user["first_name"];

    $_SESSION["middle_name"] =
        $user["middle_name"];

    $_SESSION["id_type"] =
        $user["id_type"];

    $_SESSION["last_name"] =
        $user["last_name"];

    if (!session_write_close()) {
        throw new Exception(
            "Could not save the login session."
        );
    }

    $response = [
        "success" => true,
        "message" => "Login successful."
    ];

} catch (Exception $e) {

    $response = [
        "success" => false,
        "message" => $e->getMessage()
    ];
}

echo json_encode(
    $response
);