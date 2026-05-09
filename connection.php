<?php
    $db_server = '127.0.0.1';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'campus_link';
    
    try {
        // Use $con consistently
        $con = mysqli_connect($db_server, $db_user, $db_pass, $db_name);
    } catch(mysqli_sql_exception) {
        echo "Not Connected";
        exit(); 
    }
?>