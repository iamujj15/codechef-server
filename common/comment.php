<?php

// Function to check if tables for comments exist and create them if they don't

function check_comments_table(&$E_A, &$conn)
{
    $tables_query = "SHOW TABLES LIKE 'comments'";
    $tables_result = $conn->query($tables_query);

    if ($tables_result->num_rows == 0) {
        $sql = "CREATE TABLE comments (
            id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            blog_id INT(11) UNSIGNED NOT NULL,
            parent_id INT(11) UNSIGNED DEFAULT NULL,
            posted_by VARCHAR(50),
            comment_value LONGTEXT,
            reg_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            upvotes INT(11) UNSIGNED DEFAULT 0,
            downvotes INT(11) UNSIGNED DEFAULT 0,
            is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
            deleted_message VARCHAR(50) DEFAULT NULL,
            is_hidden BOOLEAN NOT NULL DEFAULT FALSE,
            hidden_message VARCHAR(50) DEFAULT NULL,
            reply_count_on_top_cmmnt_all INT(11) UNSIGNED DEFAULT NULL,
            isSpam BOOLEAN NOT NULL DEFAULT FALSE,
            nesting_level INT(11) UNSIGNED DEFAULT 0
        )";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error creating comments table" . $conn->error;
        }
    }

    $tables_query = "SHOW TABLES LIKE 'comments_upvoted_by'";
    $tables_result = $conn->query($tables_query);

    if ($tables_result->num_rows == 0) {
        $sql = "CREATE TABLE comments_upvoted_by (
                comment_id INT(11) UNSIGNED NOT NULL,
                user_id VARCHAR(50) NOT NULL,
                PRIMARY KEY (comment_id, user_id),
                FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
            )";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error creating comments_upvoted_by table" . $conn->error;
        }
    }

    $tables_query = "SHOW TABLES LIKE 'comments_downvoted_by'";
    $tables_result = $conn->query($tables_query);

    if ($tables_result->num_rows == 0) {
        $sql = "CREATE TABLE comments_downvoted_by (
                    comment_id INT(11) UNSIGNED NOT NULL,
                    user_id VARCHAR(50) NOT NULL,
                    PRIMARY KEY (comment_id, user_id),
                    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
                )";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error creating comments_downvoted_by table" . $conn->error;
        }
    }


    $tables_query = "SHOW TABLES LIKE 'comments_reported_by'";
    $tables_result = $conn->query($tables_query);

    if ($tables_result->num_rows == 0) {
        $sql = "CREATE TABLE comments_reported_by (
                comment_id INT(11) UNSIGNED NOT NULL,
                user_id VARCHAR(50) NOT NULL,
                PRIMARY KEY (comment_id, user_id),
                FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
            )";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error creating comments_reported_by table" . $conn->error;
        }
    }
}

function post_comment(&$blog_id, &$parent_id, &$posted_by, &$comment_value, &$nesting_level, &$E_A, &$conn)
{
    check_comments_table($E_A, $conn);

    if ($parent_id != NULL) {
        $sql = "SELECT * FROM comments WHERE id = $parent_id";
        $result = $conn->query($sql);

        if ($result->num_rows == 0) {
            $E_A[] = "Parent comment does not exist!";
            return;
        }
    }

    if (count($E_A) > 0) {
        return;
    }

    if ($nesting_level > 0) {
        $pid = $parent_id;
        while ($pid != NULL) {
            $sql = "SELECT parent_id, id FROM comments WHERE id = $pid";
            $result = $conn->query($sql);
            $result = $result->fetch_assoc();
            $pid = $result['parent_id'];

            if ($pid == NULL) {
                $pid = $result['id'];
                break;
            }
        }

        $sql = "SELECT reply_count_on_top_cmmnt_all FROM comments WHERE id = $pid";
        $result = $conn->query($sql);
        $result = $result->fetch_assoc();

        $value = 1;

        if ($result['reply_count_on_top_cmmnt_all'] != NULL) {
            $value = $result['reply_count_on_top_cmmnt_all'] + 1;
        }

        if ($value > 50) {
            $E_A[] = "Maximum total replies reached! You cannot reply to this comment anymore!";
            return;
        }

        $sql = "UPDATE comments SET reply_count_on_top_cmmnt_all = $value WHERE id = $pid";
        $result = $conn->query($sql);
    }

    if ($parent_id == NULL)
        $sql = "INSERT INTO comments (blog_id, posted_by, comment_value, nesting_level) VALUES ('$blog_id', '$posted_by', '$comment_value', '$nesting_level')";
    else
        $sql = "INSERT INTO comments (blog_id, parent_id, posted_by, comment_value, nesting_level) VALUES ('$blog_id', '$parent_id', '$posted_by', '$comment_value', '$nesting_level')";
    $result = $conn->query($sql);

    if ($result === FALSE) {
        $E_A[] = "Error posting comment: " . $conn->error;
    } else {
        $E_A[] = "Comment posted successfully!";
    }
}

function upvote_button_fn(&$comment_id, &$user_id, &$E_A, &$conn)
{
    $sql = "SELECT * FROM comments WHERE id = $comment_id";
    $result = $conn->query($sql);

    if ($result->num_rows == 0) {
        $E_A[] = "Comment does not exist!";
        return array();
    }

    $sql = "SELECT * FROM comments_upvoted_by WHERE comment_id = $comment_id AND user_id = '$user_id'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $sql = "DELETE FROM comments_upvoted_by WHERE comment_id = $comment_id AND user_id = '$user_id'";
        $result = $conn->query($sql);

        $sql = "UPDATE comments SET upvotes = upvotes - 1 WHERE id = $comment_id";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error removing upvote from comment: " . $conn->error;
        } else {
            $E_A[] = "Upvote removed successfully!";
        }

        $sql = "SELECT upvotes, posted_by FROM comments WHERE id = $comment_id";
        $result = $conn->query($sql);
        $row = $result->fetch_assoc();

        $upvotes = $row['upvotes'];
        $posted_by = $row['posted_by'];

        if ($upvotes == 9) {
            $sql = "UPDATE users SET moderator = moderator - 1 WHERE username = '$posted_by'";
            $result = $conn->query($sql);

            if ($result === FALSE) {
                $E_A[] = "Error removing moderator from user: " . $conn->error;
            } else {
                $E_A[] = "User removed as moderator successfully!";
            }
        }

        return array(false);
    } else {
        $sql = "INSERT INTO comments_upvoted_by (comment_id, user_id) VALUES ('$comment_id', '$user_id')";
        $result = $conn->query($sql);

        $sql = "UPDATE comments SET upvotes = upvotes + 1 WHERE id = $comment_id";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error upvoting comment: " . $conn->error;
        } else {
            $E_A[] = "Comment upvoted successfully!";
        }

        $sql = "SELECT * FROM comments_downvoted_by WHERE comment_id = $comment_id AND user_id = '$user_id'";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            $sql = "DELETE FROM comments_downvoted_by WHERE comment_id = $comment_id AND user_id = '$user_id'";
            $result = $conn->query($sql);

            $sql = "UPDATE comments SET downvotes = downvotes - 1 WHERE id = $comment_id";
            $result = $conn->query($sql);

            if ($result === FALSE) {
                $E_A[] = "Error removing downvote from comment: " . $conn->error;
            } else {
                $E_A[] = "Downvote removed successfully!";
            }
        }

        $sql = "SELECT upvotes, posted_by FROM comments WHERE id = $comment_id";
        $result = $conn->query($sql);
        $row = $result->fetch_assoc();

        $upvotes = $row['upvotes'];
        $posted_by = $row['posted_by'];

        if ($upvotes == 10) {
            $sql = "UPDATE users SET moderator = moderator + 1 WHERE username = '$posted_by'";
            $result = $conn->query($sql);

            if ($result === FALSE) {
                $E_A[] = "Error making user a moderator: " . $conn->error;
            } else {
                $E_A[] = "User made a moderator successfully!";
            }
        }

        return array(true, false);
    }
}

function downvote_button_fn(&$comment_id, &$user_id, &$E_A, &$conn)
{
    $sql = "SELECT * FROM comments WHERE id = $comment_id";
    $result = $conn->query($sql);

    if ($result->num_rows == 0) {
        $E_A[] = "Comment does not exist!";
        return;
    }

    $sql = "SELECT * FROM comments_downvoted_by WHERE comment_id = $comment_id AND user_id = '$user_id'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $sql = "DELETE FROM comments_downvoted_by WHERE comment_id = $comment_id AND user_id = '$user_id'";
        $result = $conn->query($sql);

        $sql = "UPDATE comments SET downvotes = downvotes - 1 WHERE id = $comment_id";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error removing downvote from comment: " . $conn->error;
        } else {
            $E_A[] = "Downvote removed successfully!";
        }

        return array(false);
    } else {
        $sql = "INSERT INTO comments_downvoted_by (comment_id, user_id) VALUES ('$comment_id', '$user_id')";
        $result = $conn->query($sql);

        $sql = "UPDATE comments SET downvotes = downvotes + 1 WHERE id = $comment_id";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error downvoting comment: " . $conn->error;
        } else {
            $E_A[] = "Comment downvoted successfully!";
        }

        $sql = "SELECT * FROM comments_upvoted_by WHERE comment_id = $comment_id AND user_id = '$user_id'";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            $sql = "DELETE FROM comments_upvoted_by WHERE comment_id = $comment_id AND user_id = '$user_id'";
            $result = $conn->query($sql);

            $sql = "UPDATE comments SET upvotes = upvotes - 1 WHERE id = $comment_id";
            $result = $conn->query($sql);

            if ($result === FALSE) {
                $E_A[] = "Error removing upvote from comment: " . $conn->error;
            } else {
                $E_A[] = "Upvote removed successfully!";
            }
        }

        return array(true, false);
    }
}

function edit_button_fn(&$comment_id, &$comment_value, &$E_A, &$conn)
{
    $sql = "SELECT * FROM comments WHERE id = $comment_id";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $sql = "UPDATE comments SET comment_value = '$comment_value' WHERE id = $comment_id";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error editing comment: " . $conn->error;
        } else {
            $E_A[] = "Comment edited successfully!";
        }
    }
}

function delete_button_fn(&$comment_id, &$user_id, &$E_A, &$conn)
{
    $sql = "SELECT * FROM comments WHERE id = $comment_id";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $result = $result->fetch_assoc();
        $posted_by = $result['posted_by'];

        $sql = "SELECT moderator FROM users WHERE username = '$user_id'";
        $result = $conn->query($sql);
        $result = $result->fetch_assoc();

        $is_moderator = $result['moderator'];

        $deleted_message = "This comment was deleted";
        if ($posted_by != $user_id && $is_moderator > 0) {
            $deleted_message .= " by a moderator";
        }

        $sql = "UPDATE comments SET posted_by = NULL, comment_value = NULL, reg_date = NULL, upvotes = NULL, downvotes = NULL, is_deleted = true, deleted_message = '$deleted_message', is_hidden = false, hidden_message = NULL, isSpam = false WHERE id = $comment_id";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error deleting comment: " . $conn->error;
        } else {
            $E_A[] = "Comment deleted successfully!";
        }
    }
}

function report_button_fn(&$comment_id, &$user_id, &$E_A, &$conn)
{
    $sql = "SELECT * FROM comments_reported_by WHERE comment_id = $comment_id AND user_id = '$user_id'";
    $result = $conn->query($sql);

    if ($result->num_rows == 0) {
        $sql = "INSERT INTO comments_reported_by (comment_id, user_id) VALUES ('$comment_id', '$user_id')";
        $result = $conn->query($sql);

        if ($result === true) {
            $E_A[] = "Comment reported successfully!";
        } else {
            $E_A[] = "Error reporting comment: " . $conn->error;
        }

        $sql = "SELECT isSpam FROM comments WHERE id = $comment_id";
        $result = $conn->query($sql);
        $row = $result->fetch_assoc();

        if ($row['isSpam'] == false) {
            $sql = "SELECT COUNT(*) AS spam_count FROM comments_reported_by WHERE comment_id = $comment_id";
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            $spam_count = $row['spam_count'];

            if ($spam_count >= 5) {
                $hidden_message = "This comment is hidden because of spam";
                $sql = "UPDATE comments SET isSpam = true, is_hidden = true, hidden_message = '$hidden_message' WHERE id = $comment_id";
                $result = $conn->query($sql);

                if ($result === FALSE) {
                    $E_A[] = "Error hiding comment: " . $conn->error;
                } else {
                    $E_A[] = "Comment hidden successfully!";
                }
            }
        }
    }
}

// ---------------------- Functions for blogs ---------------------- //

function check_blogs_table(&$E_A, &$conn)
{
    $tables_query = "SHOW TABLES LIKE 'blogs'";
    $tables_result = $conn->query($tables_query);

    if ($tables_result->num_rows == 0) {
        $sql = "CREATE TABLE blogs (
            id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            blog_value LONGTEXT,
            img_url VARCHAR(255)
        )";
        $result = $conn->query($sql);

        if ($result === FALSE) {
            $E_A[] = "Error creating blogs table" . $conn->error;
        }
    }
}

function get_basic_blogs_details(&$conn, $qty = PHP_INT_MAX)
{
    $sql = "SELECT title FROM blogs";
    $result = $conn->query($sql);
    $lmt = min(max($qty, 0), $result->num_rows);

    $sql = "SELECT id, title, img_url FROM blogs LIMIT $lmt";
    $result = $conn->query($sql);

    $blogs = array();
    while ($row = $result->fetch_assoc()) {
        $blogs[] = $row;
    }
    return $blogs;
}

function get_blogs_details(&$conn, $qty = PHP_INT_MAX)
{
    $sql = "SELECT title FROM blogs";
    $result = $conn->query($sql);
    $lmt = min(max($qty, 0), $result->num_rows);

    $sql = "SELECT id, title, img_url, blog_value FROM blogs LIMIT $lmt";
    $result = $conn->query($sql);

    $blogs = array();
    while ($row = $result->fetch_assoc()) {
        $blogs[] = $row;
    }
    return $blogs;
}

$comments_selection_fields = "comments.id, parent_id, posted_by, comment_value, reg_date, upvotes, downvotes, is_deleted, deleted_message, is_hidden, hidden_message, reply_count_on_top_cmmnt_all, nesting_level";

function get_blog(&$id, &$conn)
{
    $E_A = array();
    check_comments_table($E_A, $conn);

    $sql = "SELECT title, blog_value, img_url FROM blogs WHERE id = $id";
    $result = $conn->query($sql);

    if ($result->num_rows == 0) {
        return NULL;
    }

    $result = $result->fetch_assoc();
    $blog = array(
        'title' => $result['title'],
        'blog_value' => $result['blog_value'],
        'img_url' => $result['img_url']
    );

    global $comments_selection_fields;
    $sql = "SELECT $comments_selection_fields FROM blogs JOIN comments ON blogs.id = comments.blog_id WHERE blogs.id = $id ORDER BY comments.reg_date";
    $result = $conn->query($sql);

    if ($result->num_rows == 0) {
        return $blog;
    }

    $comments = array();
    while ($row = $result->fetch_assoc()) {
        $comments[] = $row;
    }

    $blog['comments'] = $comments;

    return $blog;
}

// function create_blog(&$title, &$blog_value, &$img_url, &$E_A, &$conn)
// {
//     check_blogs_table($E_A, $conn);

//     if (count($E_A) > 0) {
//         return;
//     }

//     $sql = "INSERT INTO blogs (title, blog_value, img_url) VALUES ('$title', '$blog_value', '$img_url')";
//     $result = $conn->query($sql);

//     if ($result === FALSE) {
//         $E_A[] = "Error creating blog: " . $conn->error;
//     } else {
//         $E_A[] = "Blog created successfully!";
//     }
// }

?>