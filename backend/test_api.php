<?php

/**
 * Simple API Test Script for Viral Title Generator
 * Run this after setting up the application to test the endpoints
 */

$baseUrl = 'http://localhost:8000/api/v1';

echo "🧪 Testing Viral Title Generator API\n";
echo "=====================================\n\n";

// Test 1: Health Check
echo "1. Testing Health Check...\n";
$response = file_get_contents($baseUrl . '/health');
if ($response) {
    $data = json_decode($response, true);
    echo "✅ Health Check: " . ($data['status'] ?? 'unknown') . "\n";
} else {
    echo "❌ Health Check failed\n";
}

// Test 2: List Templates
echo "\n2. Testing Template Listing...\n";
$response = file_get_contents($baseUrl . '/templates?per_page=3');
if ($response) {
    $data = json_decode($response, true);
    if ($data['success']) {
        echo "✅ Templates found: " . count($data['templates']) . "\n";
        foreach ($data['templates'] as $template) {
            echo "   - " . $template['template_text'] . " (Score: " . $template['virality_score'] . ")\n";
        }
    } else {
        echo "❌ Template listing failed: " . ($data['message'] ?? 'unknown error') . "\n";
    }
} else {
    echo "❌ Template listing failed\n";
}

// Test 3: List Keywords
echo "\n3. Testing Keyword Listing...\n";
$response = file_get_contents($baseUrl . '/keywords?per_page=3');
if ($response) {
    $data = json_decode($response, true);
    if ($data['success']) {
        echo "✅ Keywords found: " . count($data['keywords']) . "\n";
        foreach ($data['keywords'] as $keyword) {
            echo "   - " . $keyword['keyword'] . " (" . $keyword['type'] . ", Weight: " . $keyword['weight'] . ")\n";
        }
    } else {
        echo "❌ Keyword listing failed: " . ($data['message'] ?? 'unknown error') . "\n";
    }
} else {
    echo "❌ Keyword listing failed\n";
}

// Test 4: Generate Title
echo "\n4. Testing Title Generation...\n";
$postData = json_encode([
    'project' => 'Build AI app with machine learning',
    'max_variants' => 3,
    'category' => 'tech',
    'tone' => 'professional'
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => $postData
    ]
]);

$response = file_get_contents($baseUrl . '/generate-title', false, $context);
if ($response) {
    $data = json_decode($response, true);
    if ($data['success']) {
        echo "✅ Title generation successful!\n";
        echo "   Template: " . $data['template']['template_text'] . "\n";
        echo "   Generated titles:\n";
        foreach ($data['generated_titles'] as $title) {
            echo "   - " . $title['title'] . " (Score: " . $title['virality_score'] . ")\n";
        }
    } else {
        echo "❌ Title generation failed: " . ($data['message'] ?? 'unknown error') . "\n";
    }
} else {
    echo "❌ Title generation failed\n";
}

// Test 5: Search Templates
echo "\n5. Testing Template Search...\n";
$response = file_get_contents($baseUrl . '/templates/search?query=AI&limit=3');
if ($response) {
    $data = json_decode($response, true);
    if ($data['success']) {
        echo "✅ Template search successful!\n";
        echo "   Query: " . $data['query'] . "\n";
        echo "   Results found: " . count($data['templates']) . "\n";
        foreach ($data['templates'] as $template) {
            echo "   - " . $template['template_text'] . " (Similarity: " . ($template['similarity_score'] ?? 'N/A') . ")\n";
        }
    } else {
        echo "❌ Template search failed: " . ($data['message'] ?? 'unknown error') . "\n";
    }
} else {
    echo "❌ Template search failed\n";
}

// Test 6: Get Stats
echo "\n6. Testing Statistics...\n";
$response = file_get_contents($baseUrl . '/stats');
if ($response) {
    $data = json_decode($response, true);
    if ($data['success']) {
        echo "✅ Statistics retrieved successfully!\n";
        echo "   Total Templates: " . $data['stats']['total_templates'] . "\n";
        echo "   Active Templates: " . $data['stats']['active_templates'] . "\n";
        echo "   Total Keywords: " . $data['stats']['total_keywords'] . "\n";
        echo "   Trending Keywords: " . $data['stats']['trending_keywords'] . "\n";
        echo "   Total Queries: " . $data['stats']['total_queries'] . "\n";
    } else {
        echo "❌ Statistics failed: " . ($data['message'] ?? 'unknown error') . "\n";
    }
} else {
    echo "❌ Statistics failed\n";
}

echo "\n🎉 API Testing Complete!\n";
echo "========================\n";
echo "If you see mostly ✅ marks, your API is working correctly!\n";
echo "If you see ❌ marks, check your database connection and migrations.\n";

