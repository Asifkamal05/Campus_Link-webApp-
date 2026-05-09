<?php
session_start();
include 'connection.php';

// Handle AJAX Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_input'])) {
    header('Content-Type: application/json');
    $user_input = trim($_POST['user_input']);

    // Include config if it exists (for local development)
    if (file_exists('config.php')) {
        include_once 'config.php';
    }
    
    $hf_api_token = defined('HF_API_TOKEN') ? HF_API_TOKEN : ""; // Get token from config.php

    // Using the new HF Router API (OpenAI compatible)
    $model_url = "https://router.huggingface.co/v1/chat/completions";
    $model_id = "meta-llama/Llama-3.1-8B-Instruct";

    $system_prompt = "You are an AI assistant for a university platform called CampusLink.

Your job is to convert user questions into SQL queries based on the database schema below and return ONLY the SQL query. Do not explain anything.

Database Schema:

students(student_id, username, passwd, f_name, address, birth_day, email, phone)

products(product_id, owner_id, product_title, description, price, qty, status)

services(service_id, student_id, service_title, description, price)

companies(company_id, username, name, address, email, phone)

jobs(job_id, company_id, job_title, description, salary)

reviews(review_id, reviewer_id, service_id, product_id, company_id, rating, comment)

Rules:
- Only generate SELECT queries
- Do not modify database
- Use simple SQL (MySQL compatible)
- If user asks for \"jobs\", query jobs table
- If user asks for \"products\", query products table
- If user asks for \"services\", query services table
- IMPORTANT: If a user asks for information about \"students\" or \"companies\", or anything else not related to jobs, products, or services, DO NOT generate a SQL query. Instead, return the exact text: \"UNAUTHORIZED_REQUEST\"

Examples:

User: Is there any job for students?
SQL: SELECT * FROM jobs;

User: Show all products under electronics
SQL: SELECT * FROM products WHERE description LIKE '%electronics%';

User: Show services under tutoring
SQL: SELECT * FROM services WHERE service_title LIKE '%tutoring%';

User: Show jobs with salary above 500
SQL: SELECT * FROM jobs WHERE salary > 500;";

    $data = [
        "model" => $model_id,
        "messages" => [
            ["role" => "system", "content" => $system_prompt],
            ["role" => "user", "content" => $user_input]
        ],
        "max_tokens" => 200,
        "temperature" => 0.1
    ];

    $ch = curl_init($model_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $headers = [
        "Content-Type: application/json"
    ];
    if (!empty($hf_api_token)) {
        $headers[] = "Authorization: " . $hf_api_token;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);


    if ($httpcode >= 200 && $httpcode < 300) {
        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            $generated_sql = trim($result['choices'][0]['message']['content']);
            // Remove any markdown code blocks if the model insists on adding them
            $generated_sql = preg_replace('/^```sql\s*(.*?)\s*```$/is', '$1', $generated_sql);
            $generated_sql = preg_replace('/^```\s*(.*?)\s*```$/is', '$1', $generated_sql);

            // Check for unauthorized access
            $unauthorized_msg = "Sorry I cant help you with this information right now. If you need more information about Campus_LInk .Mail us at Support@campuslink.com ";

            $is_authorized_table = preg_match('/\b(jobs|products|services)\b/i', $generated_sql);

            if (
                $generated_sql === "UNAUTHORIZED_REQUEST" ||
                !$is_authorized_table ||
                preg_match('/\b(student|students|company|companies)\b/i', $generated_sql) ||
                preg_match('/\b(student|students|company|companies)\b/i', $user_input)
            ) {
                echo json_encode(["status" => "success", "response" => $unauthorized_msg]);
                exit();
            }

            // Ensure we only return the first SQL statement if multiple are provided
            if (stripos($generated_sql, "SELECT") !== false) {
                $parts = explode(";", $generated_sql);
                $generated_sql = trim($parts[0]);

                // Execute the SQL query
                try {
                    $db_result = mysqli_query($con, $generated_sql);
                    if ($db_result) {
                        if (mysqli_num_rows($db_result) > 0) {
                            $html_output = "<div class='table-responsive'><table class='result-table'><thead><tr>";
                            $fields = mysqli_fetch_fields($db_result);
                            foreach ($fields as $field) {
                                $html_output .= "<th>" . htmlspecialchars($field->name) . "</th>";
                            }
                            $html_output .= "</tr></thead><tbody>";
                            while ($row = mysqli_fetch_assoc($db_result)) {
                                $html_output .= "<tr>";
                                foreach ($row as $value) {
                                    $html_output .= "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
                                }
                                $html_output .= "</tr>";
                            }
                            $html_output .= "</tbody></table></div>";
                            echo json_encode(["status" => "success", "response" => $html_output]);
                        } else {
                            echo json_encode(["status" => "success", "response" => "No results found for your query. <br><small>Query: " . htmlspecialchars($generated_sql) . "</small>"]);
                        }
                    } else {
                        echo json_encode(["status" => "error", "response" => "Database Error: " . mysqli_error($con) . "<br><small>Failed Query: " . htmlspecialchars($generated_sql) . "</small>"]);
                    }
                } catch (Exception $e) {
                    echo json_encode(["status" => "error", "response" => "Execution Error: " . $e->getMessage()]);
                }
            } else {
                echo json_encode(["status" => "error", "response" => "The AI generated a non-SELECT query, which is not allowed for security reasons. <br><small>AI Response: " . htmlspecialchars($generated_sql) . "</small>"]);
            }
        } else if (isset($result['error'])) {
            echo json_encode(["status" => "error", "response" => "API Error: " . $result['error']['message']]);
        } else {
            echo json_encode(["status" => "error", "response" => "Could not parse response from HF Router: " . $response]);
        }
    } else {
        $errResp = json_decode($response, true);
        $errMsg = $errResp['error']['message'] ?? "Unknown Error (HTTP Code $httpcode)";
        if ($httpcode == 401 || $httpcode == 403 || $httpcode == 404 || strpos($errMsg, 'token') !== false) {
            echo json_encode(["status" => "error", "response" => "API Authentication or Routing Error. API said: $errMsg"]);
        } else {
            echo json_encode(["status" => "error", "response" => "API Request Failed: " . $errMsg]);
        }
    }

    exit();
}

$username = htmlspecialchars($_SESSION['username'] ?? 'Guest');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink | AI Assistant</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Premium Aesthetics for Chatbot UI */
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf4 100%);
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        .chat-container {
            max-width: 900px;
            margin: 40px auto;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 80vh;
            border: 1px solid rgba(255, 255, 255, 0.4);
        }

        .chat-header {
            padding: 24px 32px;
            background: linear-gradient(90deg, #1A2980 0%, #26D0CE 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-header h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-header h2 span {
            font-size: 0.9rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 500;
        }

        .chat-header a {
            color: white;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.15);
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .chat-header a:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
        }

        .chat-box {
            flex: 1;
            padding: 32px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
            scroll-behavior: smooth;
        }

        .chat-box::-webkit-scrollbar {
            width: 6px;
        }

        .chat-box::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .message {
            max-width: 75%;
            padding: 16px 20px;
            border-radius: 16px;
            line-height: 1.5;
            font-size: 0.95rem;
            animation: fadeIn 0.3s ease-in-out;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            position: relative;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.bot {
            background: linear-gradient(145deg, #ffffff, #fdfdfd);
            align-self: flex-start;
            border: 1px solid #e2e8f0;
            color: #334155;
            border-bottom-left-radius: 4px;
            max-width: 90%;
        }

        .table-responsive {
            overflow-x: auto;
            margin-top: 10px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .result-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            background: white;
        }

        .result-table th {
            background: #f8fafc;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            color: #475569;
            font-weight: 600;
        }

        .result-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #64748b;
        }

        .result-table tr:last-child td {
            border-bottom: none;
        }

        .result-table tr:hover {
            background-color: #f8fafc;
        }

        .message.bot code {
            display: none;
            /* Hide SQL code by default */
        }

        .message.user {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
        }

        .chat-input-area {
            padding: 24px 32px;
            background: white;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .chat-input-area input {
            flex: 1;
            padding: 16px 24px;
            border: 2px solid #e2e8f0;
            border-radius: 100px;
            font-size: 1rem;
            outline: none;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02);
        }

        .chat-input-area input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .chat-input-area button {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border: none;
            padding: 16px 32px;
            border-radius: 100px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chat-input-area button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.3);
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }

        .chat-input-area button:active {
            transform: translateY(0);
        }

        .typing-indicator {
            display: none;
            align-self: flex-start;
            background: white;
            padding: 12px 16px;
            border-radius: 16px;
            border-bottom-left-radius: 4px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            margin-bottom: 10px;
        }

        .typing-indicator span {
            display: inline-block;
            width: 6px;
            height: 6px;
            background-color: #94a3b8;
            border-radius: 50%;
            margin: 0 2px;
            animation: bounce 1.4s infinite ease-in-out both;
        }

        .typing-indicator span:nth-child(1) {
            animation-delay: -0.32s;
        }

        .typing-indicator span:nth-child(2) {
            animation-delay: -0.16s;
        }

        @keyframes bounce {

            0%,
            80%,
            100% {
                transform: scale(0);
            }

            40% {
                transform: scale(1);
            }
        }

        .empty-state {
            margin: auto;
            text-align: center;
            color: #64748b;
        }

        .empty-state h3 {
            color: #334155;
            margin-bottom: 8px;
        }

        /* Mobile tweaking */
        @media (max-width: 768px) {
            .chat-container {
                height: 100vh;
                margin: 0;
                border-radius: 0;
            }
        }
    </style>
</head>

<body>

    <div class="chat-container">
        <div class="chat-header">
            <h2>✨ CAMPUS_LINK Assistant<span>AI Assistant</span></h2>
            <a href="homepage.php">Back to Home</a>
        </div>

        <div class="chat-box" id="chat-box">
            <div class="empty-state" id="empty-state">
                <h3>Welcome to CampusLink AI</h3>
                <p>Ask me a question about our platform, and I'll help you!</p>
                <p style="font-size:0.85em; margin-top:10px; color:#94a3b8;">Try: "Show jobs with salary above 500"</p>
            </div>
            <!-- Messages will be appended here -->
            <div class="typing-indicator" id="typing-indicator">
                <span></span><span></span><span></span>
            </div>
        </div>

        <div class="chat-input-area">
            <input type="text" id="user-input" placeholder="Type your Questions here..." autocomplete="off"
                onkeypress="handleKeyPress(event)">
            <button id="send-btn" onclick="sendMessage()">
                Send
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 0 22 2"></polygon>
                </svg>
            </button>
        </div>
    </div>

    <script>
        const chatBox = document.getElementById('chat-box');
        const userInput = document.getElementById('user-input');
        const typingIndicator = document.getElementById('typing-indicator');
        const sendBtn = document.getElementById('send-btn');
        const emptyState = document.getElementById('empty-state');

        function handleKeyPress(e) {
            if (e.key === 'Enter') {
                sendMessage();
            }
        }

        function addMessage(text, sender, isSql = false) {
            if (emptyState) emptyState.style.display = 'none';

            const msgDiv = document.createElement('div');
            msgDiv.classList.add('message', sender);

            if (isSql) {
                msgDiv.innerHTML = `<div class='bot-results'>${text}</div>`;
            } else {
                msgDiv.textContent = text;
            }

            chatBox.insertBefore(msgDiv, typingIndicator);
            scrollToBottom();
        }

        function scrollToBottom() {
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function sendMessage() {
            const text = userInput.value.trim();
            if (!text) return;

            addMessage(text, 'user');
            userInput.value = '';

            // Show typing indicator
            typingIndicator.style.display = 'block';
            sendBtn.disabled = true;
            scrollToBottom();

            // AJAX Request
            const formData = new FormData();
            formData.append('user_input', text);

            fetch('chatbot.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    typingIndicator.style.display = 'none';
                    sendBtn.disabled = false;

                    if (data.status === 'success') {
                        addMessage(data.response, 'bot', true);
                    } else {
                        addMessage(data.response, 'bot');
                    }
                })
                .catch(error => {
                    typingIndicator.style.display = 'none';
                    sendBtn.disabled = false;
                    addMessage("An error occurred while connecting to the AI.", 'bot');
                    console.error('Error:', error);
                });
        }
    </script>

</body>

</html>