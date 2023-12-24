<?php
// session_cache_limiter(false);
// session_start();

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Encoding\CannotDecodeContent;
use Lcobucci\JWT\Token\InvalidTokenStructure;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\UnsupportedHeaderFound;
use Lcobucci\JWT\UnencryptedToken;

require '../vendor/autoload.php';

function getAuthorizationHeader()
{
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        //print_r($requestHeaders);
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    return $headers;
}
function jwt_token_auth($username, $token)
{
    $algorithm = new Sha256();
    $signingKey = InMemory::plainText($_ENV['JWT_SECRET_KEY']);

    $parser = new Parser(new JoseEncoder());
    try {
        $tkn_prs = $parser->parse($token);
    } catch (CannotDecodeContent | InvalidTokenStructure | UnsupportedHeaderFound $e) {
        return FALSE;
    }

    $issue_time = $tkn_prs->claims()->get('iat');
    $expire_time = $tkn_prs->claims()->get('exp');
    $user_id = $tkn_prs->claims()->get('uid');

    if ($user_id !== $username) {
        return FALSE;
    }

    $tokenBuilder = (new Builder(
        new JoseEncoder(),
        ChainedFormatter::default()));

    $new_token = $tokenBuilder
        ->issuedBy($_ENV['JWT_ISSUER'])
        ->issuedAt($issue_time)
        ->expiresAt($expire_time)
        ->withClaim('uid', $user_id)
        ->getToken($algorithm, $signingKey);

    $new_token = $new_token->toString();

    if ($new_token !== $token) {
        return FALSE;
    }

    if ($issue_time === NULL || $expire_time === NULL || $user_id === NULL) {
        return FALSE;
    }

    $now = new DateTimeImmutable();
    if ($expire_time < $now) {
        return FALSE;
    }

    return TRUE;
}

function jwt_token_generator($user_id)
{
    $tokenBuilder = (new Builder(
        new JoseEncoder(),
        ChainedFormatter::default()));
    $algorithm = new Sha256();
    $signingKey = InMemory::plainText($_ENV['JWT_SECRET_KEY']);

    $now = new DateTimeImmutable();
    $token = $tokenBuilder
        ->issuedBy($_ENV['JWT_ISSUER'])
        ->issuedAt($now)
        ->expiresAt($now->modify('+1 hour'))
        ->withClaim('uid', $user_id)
        ->getToken($algorithm, $signingKey);

    return $token->toString();
}

function check_user_tab(&$conn)
{
    $sql = "SHOW TABLES LIKE 'users'";
    $result = $conn->query($sql);

    if ($result->num_rows == 0) {
        $sql = "CREATE TABLE users (
            username VARCHAR(50) PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash LONGTEXT NOT NULL,
            moderator INT(6) NOT NULL DEFAULT 0,
            reg_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            die("Error creating table" . $conn->error);
        }
    }
}

function add_user($username, $password, $email, &$E_A, &$conn)
{
    // username is valid
    if (!preg_match('/^[a-zA-Z0-9]{5,}$/', $username)) {
        $E_A[] = "Invalid username!";
    }

    // password is valid
    if (!preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[a-zA-Z]).{8,}$/', $password)) {
        $E_A[] = "Invalid password!";
    }

    // email is valid
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $E_A[] = "Invalid email!";
    }

    if (!empty($E_A))
        return;

    // Check if user exists
    $sql = "SELECT * FROM users WHERE username='$username'";
    $conn->query($sql);
    if ($conn->affected_rows > 0) {
        $E_A[] = "Username already exists!";
    } else {
        $sql = "SELECT * FROM users WHERE email='$email'";
        $conn->query($sql);
        if ($conn->affected_rows > 0) {
            $E_A[] = "Email already exists!";
        }
    }

    if (!empty($E_A))
        return;

    // ----- Hash Password -----
    $timeTarget = 0.350; // 350 milliseconds

    $cost = 10;
    do {
        $cost++;
        $start = microtime(true);
        password_hash("test", PASSWORD_DEFAULT, ["cost" => $cost]);
        $end = microtime(true);
    } while (($end - $start) < $timeTarget);

    $hash = password_hash($password, PASSWORD_DEFAULT, ["cost" => $cost]);
    // ----- Hash Password -----

    $sql = "INSERT INTO users (username, email, password_hash, moderator) VALUES ('$username', '$email', '$hash', FALSE)";
    $result = $conn->query($sql);
    if ($result === FALSE) {
        $E_A[] = "Error creating user: " . $conn->error;
    } else {
        return jwt_token_generator($username);
    }
}

function register_user($username, $password, $email, &$conn)
{
    check_user_tab($conn);
    $error_array = array();
    $tkn = add_user($username, $password, $email, $error_array, $conn);

    return (!empty($error_array) ? $error_array : array('token' => $tkn));
}

function auth_user($user_id, $password, &$E_A, &$tknArr, &$conn)
{
    // check if user exists
    $sql = "SELECT * FROM users WHERE username='$user_id' OR email='$user_id'";
    $result = $conn->query($sql);

    if ($result === FALSE) {
        $E_A[] = "Error checking user: " . $conn->error;
    } else if ($result->num_rows == 0) {
        $E_A[] = "User does not exist!";
    } else {
        $sql = "SELECT password_hash FROM users WHERE username='$user_id' OR email='$user_id'";
        $result = $conn->query($sql);
        if ($result === FALSE) {
            $E_A[] = "Error checking user: " . $conn->error;
        } else {
            $hash = $result->fetch_assoc()['password_hash'];
            $password_match = password_verify($password, $hash);

            if ($password_match === TRUE) {
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET password_hash='$hash' WHERE username='$user_id' OR email='$user_id'";
                    $result = $conn->query($sql);
                    if ($result === FALSE) {
                        $E_A[] = "Error updating password: " . $conn->error;
                    }
                }

                // jwt token generation
                try {
                    $sql = "SELECT * FROM users WHERE username='$user_id' OR email='$user_id'";
                    $result = $conn->query($sql);

                    $user_id = $result->fetch_assoc()['username'];
                    $tkn = jwt_token_generator($user_id);
                    $tknArr['token'] = $tkn;
                    $tknArr['username'] = $user_id;
                } catch (Exception $e) {
                    $E_A[] = "Error generating token: " . $e->getMessage();
                }

                // Redirect to home page
                header("Location: /");
            } else {
                $E_A[] = 'Incorrect password!';
            }
        }
    }
}

function login_user($user_id, $password, &$conn)
{
    global $error_array;
    $error_array = array();

    if (strlen($user_id) == 0) {
        $error_array[] = "User ID cannot be empty!";
    }

    if (strlen($user_id) > 255) {
        $error_array[] = "User ID cannot be longer than 255 characters!";
    }

    if (strlen($password) == 0) {
        $error_array[] = "Password cannot be empty!";
    }

    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === TRUE) {
        $error_array[] = 'You are already logged in!';
        return $error_array;
    }

    if (!empty($error_array)) {
        global $error_array;
        return $error_array;
    }

    check_user_tab($conn);

    $error_array = array();
    $tknArr = array();
    auth_user($user_id, $password, $error_array, $tknArr, $conn);
    if (!empty($tknArr))
        return $tknArr;
    return $error_array;
}

// function logout_user()
// {
// It should happen client side, basically delete the cookie containing the jwt token

// Redirect to home page
//     header("Location: /");
// }

?>