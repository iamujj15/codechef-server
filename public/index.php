<?php

/* Handle CORS */

// Specify domains from which requests are allowed
header('Access-Control-Allow-Origin: http://localhost:3000');

// Credentials are allowed, which allows cookies to be sent
header('Access-Control-Allow-Credentials: true');

// Specify which request methods are allowed
header('Access-Control-Allow-Methods: PUT, GET, POST, DELETE, OPTIONS');

// Additional headers which may be sent along with the CORS request
header('Access-Control-Allow-Headers: X-Requested-With,Authorization,Content-Type');

// Set the age to 1 day to improve speed/caching.
header('Access-Control-Max-Age: 86400');

// Exit early so the page isn't fully loaded for options requests
// if (strtolower($_SERVER['REQUEST_METHOD']) == 'options') {
//     exit();
// }

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// use Dflydev\FigCookies\SetCookie;
// use Dflydev\FigCookies\FigResponseCookies;

include '../config/db_connection.php';
include '../common/user.php';
include '../common/comment.php';

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables from .env
// Access them via $_ENV['VARIABLE_NAME']
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$app = AppFactory::create();

// Parse json, form data and xml
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

// DB Connection
$conn = OpenCon();

// Routes

$app->any('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello World!");
    return $response;
});

$app->any('/auth', function (Request $request, Response $response, $args) {
    $header = getAuthorizationHeader();
    if (!preg_match('/Bearer\s(\S+)/', $header, $matches)) {
        // header('HTTP/1.0 400 Bad Request');
        $response->getBody()->write(json_encode(['authentic_request' => FALSE]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    }
    $jwt = $matches[1];
    if (!$jwt) {
        // No token was able to be extracted from the authorization header
        // header('HTTP/1.0 401 Bad Request');
        $response->getBody()->write(json_encode(['authentic_request' => FALSE]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    }

    $data = $request->getParsedBody();
    $username = $data['user_id'];

    global $auth;
    $auth = jwt_token_auth($username, $jwt);

    if ($auth == FALSE) {
        $response->getBody()->write(json_encode(['authentic_request' => $auth]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    } else {
        $response->getBody()->write(json_encode(['authentic_request' => $auth]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    }
});

$app->get("/login", function (Request $request, Response $response, $args) {
    // $response->getBody()->write(file_get_contents('../views/login.html'));
    $response->getBody()->write("Login Page");
    return $response;
});

$app->post("/login", function (Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $user_id = $data['user_id'];
    $password = $data['password'];

    global $conn;
    global $tp;
    $tp = login_user($user_id, $password, $conn);

    if (array_key_exists('token', $tp) == FALSE) {
        global $tp;
        $data = "";
        $data .= (count($tp) == 0) ? "Some Error Occured while Logging In!" : "";
        for ($i = 0; $i < count($tp); $i++) {
            $data .= $tp[$i];
        }
        $response->getBody()->write(json_encode(['message' => $data]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(400);
    } else {
        $response->getBody()->write(json_encode(['message' => 'User logged in successfully!', 'token' => $tp['token'], 'username' => $tp['username']]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withHeader('Location', '/')
            ->withStatus(200);
    }
});

$app->get("/register", function (Request $request, Response $response, $args) {
    $response->getBody()->write("Register Page");
    return $response;
});

$app->post('/register', function (Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $username = $data['username'];
    $password = $data['password'];
    $email = $data['email'];

    global $conn;
    $tp = register_user($username, $password, $email, $conn);

    if (array_key_exists('token', $tp) == FALSE) {
        $response->getBody()->write(json_encode(['message' => $tp]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withHeader('Location', '/signup')
            ->withStatus(400);
    } else {
        $response->getBody()->write(json_encode(['message' => 'User created successfully!', 'token' => $tp['token']]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(200);
    }
});

$app->any('/logout', function (Request $request, Response $response, $args) {
    $response->getBody()->write(json_encode(['message' => 'Logut should happen at client side by deleting the cookie containing the jwt token!']));
    $response = $response->withHeader('Content-Type', 'application/json');
    return $response
        ->withHeader('Location', '/')
        ->withStatus(200);
});

// provide a route to get {qty} number of basic blogs details
$app->get('/blogs/basic/{qty}', function (Request $request, Response $response, $args) {
    $qty = $args['qty'];

    if (is_numeric($qty) == FALSE) {
        $response->getBody()->write(json_encode(['message' => 'Invalid Request!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(400);
    }

    global $conn;
    $blogs_basic = get_basic_blogs_details($conn, (int) $qty);

    $response->getBody()->write(json_encode($blogs_basic));
    $response = $response->withHeader('Content-Type', 'application/json');
    return $response
        ->withStatus(200);
});

// provide a route to get {qty} number of blogs details
$app->get('/blogs/{qty}', function (Request $request, Response $response, $args) {
    $qty = $args['qty'];

    if (is_numeric($qty) == FALSE) {
        $response->getBody()->write(json_encode(['message' => 'Invalid Request!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(400);
    }

    global $conn;
    $blogs = get_blogs_details($conn, (int) $qty);

    $response->getBody()->write(json_encode($blogs));
    $response = $response->withHeader('Content-Type', 'application/json');
    return $response
        ->withStatus(200);
});

// provide a route to get details of the blog with id = {id}
$app->get('/blog/{id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];

    if (is_numeric($id) == FALSE) {
        $response->getBody()->write(json_encode(['message' => 'Invalid Request!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(400);
    }

    global $conn;
    $blog = get_blog($id, $conn);

    if (empty($blog)) {
        $response->getBody()->write(json_encode(['message' => 'No Blogs Found!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(404);
    }

    $response->getBody()->write(json_encode($blog));
    $response = $response->withHeader('Content-Type', 'application/json');
    return $response
        ->withStatus(200);
});

// provide a route to post a comment on the blog with id = {id}
$app->post('/blog/{id}/comment', function (Request $request, Response $response, $args) {
    $id = $args['id'];

    if (is_numeric($id) == FALSE) {
        $response->getBody()->write(json_encode(['message' => 'Invalid Request!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(400);
    }

    $data = $request->getParsedBody();
    $blog_id = $data['blog_id'];
    $parent_id = $data['parent_id'];
    $comment_value = $data['comment_value'];
    $posted_by = $data['posted_by'];
    $nesting_level = $data['nesting_level'];

    global $conn;
    $E_A = array();
    post_comment($id, $parent_id, $posted_by, $comment_value, $nesting_level, $E_A, $conn);

    if (count($E_A) > 0) {
        $response->getBody()->write(json_encode(['message' => $E_A]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    }

    $response->getBody()->write(json_encode(['message' => 'Comment Created Successfully!']));
    $response = $response->withHeader('Content-Type', 'application/json');
    return $response
        ->withStatus(200);
});

// provide a route to toggle upvote on the comment with id = {id}
$app->post('/blog/{id}/comment/{comment_id}/upvote', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $comment_id = $args['comment_id'];

    if (is_numeric($id) == FALSE || is_numeric($comment_id) == FALSE) {
        $response->getBody()->write(json_encode(['message' => 'Invalid Request!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(400);
    }

    $data = $request->getParsedBody();
    $user_id = $data['user_id'];

    global $conn;
    $E_A = array();
    $upvotes = upvote_button_fn($comment_id, $user_id, $E_A, $conn);

    if (count($E_A) > 0) {
        if (count($upvotes) == 0) {
            $response->getBody()->write(json_encode(['message' => $E_A]));
            $response = $response->withHeader('Content-Type', 'application/json');
            return $response
                ->withStatus(200);
        } else if (count($upvotes) == 1) {
            $response->getBody()->write(json_encode(['message' => $E_A, 'upvote_toggled' => $upvotes[0]]));
            $response = $response->withHeader('Content-Type', 'application/json');
            return $response
                ->withStatus(200);
        } else {
            $response->getBody()->write(json_encode(['message' => $E_A, 'upvote_toggled' => $upvotes[0], 'downvote_toggled' => $upvotes[1]]));
            $response = $response->withHeader('Content-Type', 'application/json');
            return $response
                ->withStatus(200);
        }
    }

    $response->getBody()->write(json_encode(['message' => 'Upvote Toggled Successfully!']));
    $response = $response->withHeader('Content-Type', 'application/json');
    return $response
        ->withStatus(200);
});

$app->post('/blog/{id}/comment/{comment_id}/downvote', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $comment_id = $args['comment_id'];

    if (is_numeric($id) == FALSE || is_numeric($comment_id) == FALSE) {
        $response->getBody()->write(json_encode(['message' => 'Invalid Request!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(400);
    }

    $data = $request->getParsedBody();
    $user_id = $data['user_id'];

    global $conn;
    $E_A = array();
    $downvotes = downvote_button_fn($comment_id, $user_id, $E_A, $conn);

    if (count($E_A) > 0) {
        if (count($downvotes) == 0) {
            $response->getBody()->write(json_encode(['message' => $E_A]));
            $response = $response->withHeader('Content-Type', 'application/json');
            return $response
                ->withStatus(200);
        } else if (count($downvotes) == 1) {
            $response->getBody()->write(json_encode(['message' => $E_A, 'downvote_toggled' => $downvotes[0]]));
            $response = $response->withHeader('Content-Type', 'application/json');
            return $response
                ->withStatus(200);
        } else {
            $response->getBody()->write(json_encode(['message' => $E_A, 'downvote_toggled' => $downvotes[0], 'upvote_toggled' => $downvotes[1]]));
            $response = $response->withHeader('Content-Type', 'application/json');
            return $response
                ->withStatus(200);
        }
    }

    $response->getBody()->write(json_encode(['message' => 'Downvote Toggled Successfully!']));
    $response = $response->withHeader('Content-Type', 'application/json');
    return $response
        ->withStatus(200);
});

$app->put('/blog/{id}/comment/{comment_id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $comment_id = $args['comment_id'];

    if (is_numeric($id) == FALSE || is_numeric($comment_id) == FALSE) {
        $response->getBody()->write(json_encode(['message' => 'Invalid Request!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(400);
    }

    $comment_id = (int) $comment_id;

    $data = $request->getParsedBody();
    $comment_value = $data['comment_value'];

    global $conn;
    $E_A = array();
    edit_button_fn($comment_id, $comment_value, $E_A, $conn);

    if (count($E_A) > 0) {
        $response->getBody()->write(json_encode(['message' => $E_A]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    } else {
        $response->getBody()->write(json_encode(['message' => 'Comment Edited Successfully!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    }
});

$app->delete('/blog/{id}/comment/{comment_id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $comment_id = $args['comment_id'];

    if (is_numeric($id) == FALSE || is_numeric($comment_id) == FALSE) {
        $response->getBody()->write(json_encode(['message' => 'Invalid Request!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(400);
    }

    $comment_id = (int) $comment_id;
    $data = $request->getParsedBody();
    $user_id = $data['user_id'];

    global $conn;
    $E_A = array();
    delete_button_fn($comment_id, $user_id, $E_A, $conn);

    if (count($E_A) > 0) {
        $response->getBody()->write(json_encode(['message' => $E_A]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    } else {
        $response->getBody()->write(json_encode(['message' => 'Comment Deleted Successfully!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    }
});

$app->post('/blog/{id}/comment/{comment_id}/report', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $comment_id = $args['comment_id'];

    if (is_numeric($id) == FALSE || is_numeric($comment_id) == FALSE) {
        $response->getBody()->write(json_encode(['message' => 'Invalid Request!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(400);
    }

    $comment_id = (int) $comment_id;
    $data = $request->getParsedBody();
    $user_id = $data['user_id'];

    global $conn;
    $E_A = array();
    report_button_fn($comment_id, $user_id, $E_A, $conn);

    if (count($E_A) > 0) {
        $response->getBody()->write(json_encode(['message' => $E_A]));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    } else {
        $response->getBody()->write(json_encode(['message' => 'Comment Reported Successfully!']));
        $response = $response->withHeader('Content-Type', 'application/json');
        return $response
            ->withStatus(200);
    }
});

// $app->post('/blogpost', function (Request $request, Response $response, $args) {
//     $data = $request->getParsedBody();
//     $title = $data['title'];
//     $blog_value = $data['blog_value'];
//     $img_url = $data['img_url'];

//     global $conn;
//     $E_A = array();
//     create_blog($title, $blog_value, $img_url, $E_A, $conn);

//     $response->getBody()->write(json_encode(['message' => $E_A]));
//     $response = $response->withHeader('Content-Type', 'application/json');
//     return $response
//         ->withHeader('Location', '/')
//         ->withStatus(200);
// });

$app->run();

?>