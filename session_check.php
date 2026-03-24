<?php

define('SESSION_TIMEOUT', 30); 
define('SESSION_WARNING', 20); 


if (isset($_SESSION['user_id'])) {
    $now = time();

     
         if (isset($_SESSION['last_activity'])) {
                 $elapsed = $now - $_SESSION['last_activity'];

                         if ($elapsed > SESSION_TIMEOUT) {
                                     session_unset();
                                                 session_destroy();
                                                             header("Location: index.php?timeout=1");
                                                                         exit();
                                                                                 }
                                                                                     }


                                                                                         $_SESSION['last_activity'] = $now;
                                                                                             $_SESSION['session_expires'] = $now + SESSION_TIMEOUT;
                                                                                             }
                                                                                             ?>