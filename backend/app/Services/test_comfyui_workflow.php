<?php

// ComfyUI API endpoint
$url = "http://[IP_ADDRESS]/prompt";
echo "ComfyUI Start:\n";
// Load workflow JSON file
$workflowJson = file_get_contents("../Prompts/flux1-dev-q8_0.json");

if ($workflowJson === false) {
    die("Error: Could not read workflow.json\n");
}

// Initialize cURL
$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS => $workflowJson
]);

// Execute POST request
$response = curl_exec($ch);

// Handle cURL errors
if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch) . "\n";
    curl_close($ch);
    exit;
}

curl_close($ch);

// Output ComfyUI response
echo "ComfyUI Response:\n";
echo $response;