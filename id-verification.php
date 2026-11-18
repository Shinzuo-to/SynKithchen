<?php

function validateIdType($idType)
{
    $supportedTypes = [
        "national_id",
        "passport",
        "drivers_license",
        "voters_id",
        "postal_id",
        "digital_id",
        "other_government_id"
    ];

    if (!in_array($idType, $supportedTypes, true)) {
        throw new Exception("Select a supported government-issued ID type.");
    }
}

function normalizeMiddleName($middleName)
{
    $middleName = trim($middleName);
    if ($middleName === "") {
        throw new Exception("Middle name or initial is required.");
    }
    if (strlen($middleName) > 400) {
        throw new Exception("Middle name or initial must be 100 characters or fewer.");
    }

    return strtoupper($middleName);
}

function tokenizeName($name)
{
    $transliteratedName = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $name);
    if ($transliteratedName !== false) {
        $name = str_replace("'", "", $transliteratedName);
    }

    return preg_split(
        '/[^A-Z]+/',
        strtoupper($name),
        -1,
        PREG_SPLIT_NO_EMPTY
    );
}

function middleNamePartMatches($enteredPart, $parsedPart)
{
    if ($enteredPart === $parsedPart) {
        return true;
    }

    return (
        strlen($enteredPart) === 1 &&
        substr($parsedPart, 0, 1) === $enteredPart
    ) || (
        strlen($parsedPart) === 1 &&
        substr($enteredPart, 0, 1) === $parsedPart
    );
}

function namePartMatches($enteredPart, $parsedPart)
{
    if ($enteredPart === $parsedPart) {
        return true;
    }

    if (
        strlen($enteredPart) >= 4 &&
        strlen($parsedPart) >= 4 &&
        abs(strlen($enteredPart) - strlen($parsedPart)) <= 1
    ) {
        return levenshtein($enteredPart, $parsedPart) <= 1;
    }

    return false;
}

function normalizeOcrText($text)
{
    $transliteratedText = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $text);
    if ($transliteratedText !== false) {
        $text = $transliteratedText;
    }

    return strtoupper(preg_replace('/[^A-Z0-9]+/', ' ', $text));
}

function verifyIdDocumentType($ocrText, $selectedType)
{
    $text = normalizeOcrText($ocrText);

    if ($selectedType === "digital_id") {
        return;
    }

    $typePatterns = [
        "national_id" => '/\b(?:PHILSYS|PHIL ID|NATIONAL ID|NATIONAL IDENTIFICATION CARD|PHILIPPINE IDENTIFICATION (?:SYSTEM|CARD))\b/',
        "passport" => '/\bPASSPORT\b/',
        "drivers_license" => '/\bDRIVER(?:S)?(?:\s+S)?\s+LICENSE\b/',
        "voters_id" => '/\b(?:VOTER(?:S)?(?:\s+(?:ID|IDENTIFICATION|REGISTRATION))?|COMELEC|COMMISSION ON ELECTIONS)\b/',
        "postal_id" => '/\b(?:POSTAL ID|PHILPOST|PHILIPPINE POSTAL CORPORATION)\b/'
    ];
    $typeLabels = [
        "national_id" => "National ID",
        "passport" => "Passport",
        "drivers_license" => "Driver's license",
        "voters_id" => "Voter's ID",
        "postal_id" => "Postal ID"
    ];
    $detectedTypes = [];

    foreach ($typePatterns as $type => $pattern) {
        if (preg_match($pattern, $text)) {
            $detectedTypes[] = $type;
        }
    }

    if ($selectedType === "other_government_id") {
        if ($detectedTypes) {
            $detectedLabel = $typeLabels[$detectedTypes[0]];
            throw new Exception("The ID text appears to identify a " . $detectedLabel . ". Select that ID type instead.");
        }

        $hasIdLabel = preg_match('/\b(?:ID|IDENTIFICATION|IDENTITY)\b/', $text);
        $hasGovernmentLabel = preg_match('/\b(?:GOVERNMENT|REPUBLIC|DEPARTMENT|COMMISSION|BUREAU|CITY|MUNICIPAL|PHILIPPINE|PRC)\b/', $text);
        if (!$hasIdLabel || !$hasGovernmentLabel) {
            throw new Exception("Could not confirm this as another government-issued ID from its text. Upload a clear image showing its title and issuing authority.");
        }

        return;
    }

    if (in_array($selectedType, $detectedTypes, true)) {
        return;
    }

    if ($detectedTypes) {
        $detectedLabel = $typeLabels[$detectedTypes[0]];
        throw new Exception("The ID text appears to identify a " . $detectedLabel . ", not the selected type. Select the matching ID type.");
    }

    throw new Exception("Could not confirm the selected ID type from the text. Upload a clear image showing the document title, or select the correct ID type.");
}

function validateImageUpload($file)
{
    if (!isset($file["error"]) || $file["error"] !== UPLOAD_ERR_OK) {
        throw new Exception("The image could not be uploaded. Please try again.");
    }

    if (
        empty($file["tmp_name"]) ||
        !is_uploaded_file($file["tmp_name"])
    ) {
        throw new Exception("The uploaded image is invalid.");
    }

    $fileSize = filesize($file["tmp_name"]);
    if ($fileSize === false || $fileSize > 8 * 1024 * 1024) {
        throw new Exception("Images must be no larger than 8 MB.");
    }

    $imageInfo = getimagesize($file["tmp_name"]);
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($file["tmp_name"]);
    $allowedTypes = [
        "image/jpeg",
        "image/png",
        "image/webp"
    ];

    if (
        $imageInfo === false ||
        !in_array($mimeType, $allowedTypes, true)
    ) {
        throw new Exception("Upload a valid JPEG, PNG, or WebP image.");
    }

    return $mimeType;
}

function imageExtensionForMime($mimeType)
{
    $extensions = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp"
    ];

    if (!isset($extensions[$mimeType])) {
        throw new Exception("Unsupported image format.");
    }

    return $extensions[$mimeType];
}

function extractIdTextLocally($imagePath, $pageSegmentationMode)
{
    if (!in_array($pageSegmentationMode, ["6", "11"], true)) {
        throw new Exception("Invalid local text scanning mode.");
    }

    $configuredPath = getenv("TESSERACT_CMD");
    $candidates = [];
    if (is_string($configuredPath) && trim($configuredPath) !== "") {
        $candidates[] = trim($configuredPath);
    }
    $candidates[] = "C:\\Program Files\\Tesseract-OCR\\tesseract.exe";
    $candidates[] = "C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe";

    $tesseractPath = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $tesseractPath = $candidate;
            break;
        }
    }

    if ($tesseractPath === null) {
        throw new Exception("Local ID text scanning is not configured. Install Tesseract OCR or set the TESSERACT_CMD environment variable.");
    }

    $outputFile = tmpfile();
    $errorFile = tmpfile();
    if ($outputFile === false || $errorFile === false) {
        if ($outputFile !== false) {
            fclose($outputFile);
        }
        if ($errorFile !== false) {
            fclose($errorFile);
        }
        throw new Exception("The local ID text scanner could not be started.");
    }

    $process = proc_open(
        [
            $tesseractPath,
            $imagePath,
            "stdout",
            "--oem",
            "1",
            "--psm",
            $pageSegmentationMode,
            "-l",
            "eng"
        ],
        [
            0 => ["pipe", "r"],
            1 => $outputFile,
            2 => $errorFile
        ],
        $pipes
    );

    if (!is_resource($process)) {
        fclose($outputFile);
        fclose($errorFile);
        throw new Exception("The local ID text scanner could not be started.");
    }

    fclose($pipes[0]);
    $deadline = microtime(true) + 20;
    do {
        $status = proc_get_status($process);
        if (!$status["running"]) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            proc_close($process);
            fclose($outputFile);
            fclose($errorFile);
            throw new Exception("Local ID text scanning timed out. Try a smaller, clearer image.");
        }
        usleep(100000);
    } while (true);

    $exitCode = proc_close($process);
    rewind($outputFile);
    rewind($errorFile);
    $ocrText = stream_get_contents($outputFile);
    $errorText = stream_get_contents($errorFile);
    fclose($outputFile);
    fclose($errorFile);

    if ($ocrText === false || $errorText === false || $exitCode !== 0) {
        error_log("Tesseract OCR failed with exit code " . $exitCode . ": " . trim((string)$errorText));
        throw new Exception("Local ID text scanning failed. Please try a readable JPEG or PNG image.");
    }

    return $ocrText;
}

function verifyIdDocument($imagePath, $mimeType, $idType, $firstName, $lastName, $middleName = "")
{
    $middleName = normalizeMiddleName($middleName);
    validateIdType($idType);

    if (!in_array($mimeType, ["image/jpeg", "image/png", "image/webp"], true)) {
        throw new Exception("Upload a valid JPEG, PNG, or WebP ID image.");
    }

    $fileSize = filesize($imagePath);
    if ($fileSize === false) {
        throw new Exception("The ID image could not be read for text scanning.");
    }
    if ($fileSize > 1024 * 1024) {
        throw new Exception("The ID image is too large for local text scanning. Refresh the registration page and check the ID again.");
    }

    $imageInfo = getimagesize($imagePath);
    if (
        $imageInfo === false ||
        max($imageInfo[0], $imageInfo[1]) > 1800
    ) {
        throw new Exception("The ID image is too large for local text scanning. Refresh the registration page and check the ID again.");
    }

    $realPath = realpath($imagePath);
    if ($realPath === false) {
        throw new Exception("The ID image could not be opened for text scanning.");
    }

    $parsedText = extractIdTextLocally($realPath, "6");
    $parsedText .= "\n" . extractIdTextLocally($realPath, "11");
    if (trim($parsedText) === "") {
        throw new Exception("The local text scanner could not read this ID. Make sure the name is readable and the image is not blurry.");
    }

    verifyIdDocumentType($parsedText, $idType);
    $parsedWords = tokenizeName($parsedText);
    $firstAndLastParts = array_merge(
        tokenizeName($firstName),
        tokenizeName($lastName)
    );
    $middleNameParts = tokenizeName($middleName);

    foreach ($firstAndLastParts as $part) {
        $matched = false;
        foreach ($parsedWords as $parsedPart) {
            if (namePartMatches($part, $parsedPart)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            throw new Exception("The scanner could not confidently read the entered first or last name. Make sure the name is visible and try a closer, straight-on photo.");
        }
    }

    foreach ($middleNameParts as $middlePart) {
        $matched = false;
        foreach ($parsedWords as $parsedPart) {
            if (middleNamePartMatches($middlePart, $parsedPart)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            throw new Exception("The scanner could not confidently read the entered middle name or initial. Make sure it is visible and try a closer, straight-on photo.");
        }
    }
}

function createIdVerificationReceipt($idImagePath, $firstName, $middleName, $lastName, $idType)
{
    $idImageHash = hash_file("sha256", $idImagePath);
    if ($idImageHash === false) {
        throw new Exception("Could not save the ID verification result. Please check the ID again.");
    }

    return [
        "id_image_hash" => $idImageHash,
        "first_name" => strtoupper(trim($firstName)),
        "middle_name" => normalizeMiddleName($middleName),
        "last_name" => strtoupper(trim($lastName)),
        "id_type" => $idType,
        "verified_at" => time()
    ];
}

function idVerificationReceiptDirectory()
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "SynKitchen-id-verification";
    if (
        !is_dir($directory) &&
        !mkdir($directory, 0700, true) &&
        !is_dir($directory)
    ) {
        throw new Exception("Could not create temporary ID verification storage.");
    }

    if (!is_writable($directory)) {
        throw new Exception("Temporary ID verification storage is not writable.");
    }

    return $directory;
}

function idVerificationReceiptPath($token)
{
    if (
        !is_string($token) ||
        preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
    ) {
        throw new Exception("The ID check has expired or is invalid. Check the ID again.");
    }

    return idVerificationReceiptDirectory() .
        DIRECTORY_SEPARATOR .
        "receipt-" . hash("sha256", $token) . ".json";
}

function createIdVerificationReceiptToken($receipt)
{
    $directory = idVerificationReceiptDirectory();
    $entries = scandir($directory);
    if ($entries === false) {
        throw new Exception("Could not access temporary ID verification storage.");
    }

    foreach ($entries as $entry) {
        if (preg_match('/\Areceipt-[a-f0-9]{64}\.json\z/', $entry) !== 1) {
            continue;
        }

        $stalePath = $directory . DIRECTORY_SEPARATOR . $entry;
        $modifiedAt = filemtime($stalePath);
        if ($modifiedAt !== false && $modifiedAt < time() - 1800) {
            if (!unlink($stalePath)) {
                error_log("Failed to remove an expired ID verification receipt.");
            }
        }
    }

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Exception $error) {
        throw new Exception("Could not create a secure ID verification token.");
    }

    $path = idVerificationReceiptPath($token);
    $contents = json_encode($receipt);
    if ($contents === false) {
        throw new Exception("Could not save the ID verification result.");
    }

    $bytesWritten = file_put_contents($path, $contents, LOCK_EX);
    if ($bytesWritten !== strlen($contents)) {
        if (is_file($path) && !unlink($path)) {
            error_log("Failed to remove an incomplete ID verification receipt.");
        }
        throw new Exception("Could not save the ID verification result.");
    }

    return $token;
}

function readIdVerificationReceiptToken($token)
{
    $path = idVerificationReceiptPath($token);
    if (!is_file($path)) {
        throw new Exception("The ID check has expired or is missing. Check the ID again.");
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new Exception("The ID check has expired or is missing. Check the ID again.");
    }

    $receipt = json_decode($contents, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($receipt)) {
        throw new Exception("The ID check result could not be read. Check the ID again.");
    }

    return $receipt;
}

function consumeIdVerificationReceiptToken($token)
{
    $path = idVerificationReceiptPath($token);
    try {
        $claimedPath = $path . "." . bin2hex(random_bytes(8)) . ".claimed";
    } catch (Exception $error) {
        throw new Exception("Could not complete the ID check.");
    }

    if (!is_file($path) || !@rename($path, $claimedPath)) {
        throw new Exception("The ID check has expired or was already used. Check the ID again.");
    }

    if (!unlink($claimedPath)) {
        error_log("Failed to remove a consumed ID verification receipt.");
    }
}

function matchesIdVerificationReceipt($receipt, $idImagePath, $firstName, $middleName, $lastName, $idType)
{
    if (
        !is_array($receipt) ||
        !isset($receipt["verified_at"]) ||
        !is_int($receipt["verified_at"]) ||
        time() - $receipt["verified_at"] > 1800 ||
        time() < $receipt["verified_at"]
    ) {
        return false;
    }

    $idImageHash = hash_file("sha256", $idImagePath);
    if ($idImageHash === false) {
        return false;
    }

    $expectedValues = [
        "id_image_hash" => $idImageHash,
        "first_name" => strtoupper(trim($firstName)),
        "middle_name" => normalizeMiddleName($middleName),
        "last_name" => strtoupper(trim($lastName)),
        "id_type" => $idType
    ];

    foreach ($expectedValues as $key => $expectedValue) {
        if (
            !isset($receipt[$key]) ||
            !is_string($receipt[$key]) ||
            !hash_equals($receipt[$key], $expectedValue)
        ) {
            return false;
        }
    }

    return true;
}
?>
