<?php
/**
 * AI Prediction API Handler
 * This file handles the integration with AI services to predict expense details
 * from user descriptions
 */

// Include database connection
require_once('database.php');
session_start();

// API configuration
define('AI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent');
define('AI_API_KEY', 'AIzaSyC29EOW6fpCyotHzh5tOELnVvhfwXw89v0');  // Replace with your actual Gemini API key

/**
 * Process a natural language description and extract expense information
 * 
 * @param string $description User's expense description
 * @return array Structured data about the expense
 */
function processExpenseDescription($description) {
    // Prepare request data for Gemini API
    $data = array(
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => "Extract expense information from this description: \"$description\". " .
                                  "Return a JSON object with the following fields if they can be determined: " .
                                  "category (one of: Food, Transport, Entertainment, Utilities, Shopping, Health, Education, Travel, Other), " .
                                  "cost (numeric value only), " .
                                  "date (in YYYY-MM-DD format), " .
                                  "description (a cleaned up, concise version of the expense)"
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.2,
            'topP' => 1,
            'topK' => 32,
            'maxOutputTokens' => 150
        ]
    );

    // Update the cURL options
    $ch = curl_init(AI_API_URL . '?key=' . AI_API_KEY);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    
    // Execute cURL request
    $response = curl_exec($ch);
    
    // Check for errors
    if(curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return array('error' => "API request failed: $error");
    }
    
    // Close cURL session
    curl_close($ch);
    
    // Process the response
   // Process the response
    $result = json_decode($response, true);

    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return array('error' => 'Invalid response from AI service');
    }

    // Extract text from Gemini response
    $text = $result['candidates'][0]['content']['parts'][0]['text'];
    
    // Remove any non-JSON text that might be in the response
    $jsonStart = strpos($text, '{');
    $jsonEnd = strrpos($text, '}');
    
    if ($jsonStart === false || $jsonEnd === false) {
        return array('error' => 'Could not parse AI response');
    }
    
    $jsonText = substr($text, $jsonStart, $jsonEnd - $jsonStart + 1);
    $prediction = json_decode($jsonText, true);
    
    if (!$prediction) {
        return array('error' => 'Failed to parse AI prediction data');
    }
    
    // Validate prediction data
    $required_fields = array('category', 'cost', 'date');
    $missing_fields = array();
    
    foreach($required_fields as $field) {
        if(!isset($prediction[$field]) || empty($prediction[$field])) {
            $missing_fields[] = $field;
        }
    }
    
    if(!empty($missing_fields)) {
        $prediction['incomplete'] = true;
        $prediction['missing_fields'] = $missing_fields;
    }
    
    return $prediction;
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is an AJAX request
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        
        // Get POST data
        $description = isset($_POST['description']) ? $_POST['description'] : '';
        
        if (empty($description)) {
            echo json_encode(array('error' => 'No description provided'));
            exit;
        }
        
        // Process the description
        $prediction = processExpenseDescription($description);
        
        // Find category ID if category is predicted
        if (isset($prediction['category']) && !empty($prediction['category'])) {
            $userid = $_SESSION['detsuid'];
            $category_name = $prediction['category'];
            
            // Look up the category ID
            $query = "SELECT CategoryId FROM tblcategory WHERE UserId = ? AND CategoryName LIKE ?";
            $stmt = mysqli_prepare($db, $query);
            mysqli_stmt_bind_param($stmt, "is", $userid, $category_name);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($row = mysqli_fetch_assoc($result)) {
                $prediction['category_id'] = $row['CategoryId'];
            }
        }
        
        // Return the prediction as JSON
        header('Content-Type: application/json');
        echo json_encode($prediction);
        exit;
    }
}

// If accessed directly, redirect to add-expense.php
header('Location: add-expense.php');
exit;
?>