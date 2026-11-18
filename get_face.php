<?php

header('Content-Type: application/json');

require_once 'db.php';

$response = [
    'success' => false
];

try {

    if (!isset($_POST['username'])) {

        $response['message'] =
            'Username is required.';

        echo json_encode($response);
        exit;
    }

    $username =
        trim($_POST['username']);

    $stmt =
        $conn->prepare(
            "SELECT face_path
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

        $response['message'] =
            'User not found.';

        echo json_encode($response);
        exit;
    }

    $row =
        $result->fetch_assoc();

    $response = [
        'success' => true,
        'face' => $row['face_path']
    ];

    echo json_encode($response);

    $stmt->close();

} catch (Exception $e) {

    $response['message'] =
        $e->getMessage();

    echo json_encode($response);
}

$conn->close();

?>