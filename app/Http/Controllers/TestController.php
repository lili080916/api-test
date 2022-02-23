<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class TestController extends Controller
{

    public function allPosts (Request $request) {
        $response = Http::get(Config::get('constants.POSTS_URL'));

        // check if status code is 200
        if ($response->status() == 200) {
            $posts = $response->json();
            return response(["posts" => $posts], 200);
        };
    }

    public function allUsers (Request $request) {
        $response = Http::get(Config::get('constants.USERS_URL'));

        // check if status code is 200
        if ($response->status() == 200) {
            $users = $response->json();
            dd($users);
        };
    }

    public function ratingText (Request $request, $text) {
        // get all post from API
        $response = Http::get(Config::get('constants.POSTS_URL'));

        // check if status code is 200
        if ($response->status() == 200) {
            $posts = $response->json();

            $result = array();
            $resultPosts = array();
            $resultUsers = array();

            // get all ocurrences by word in all title and body text
            $globalOcurrence = $this->globalOcurrences($posts);
            $globalOcurrenceTitle = $globalOcurrence["globalOcurrenceTitle"];
            $globalOcurrenceBody = $globalOcurrence["globalOcurrenceBody"];

            $totalPointsText = $this->getPointsByOcurrences($text, $globalOcurrenceTitle, $globalOcurrenceBody);

            return response(["data" => $totalPointsText], 200);
        }
    }

    public function ratingAllPosts (Request $request) {
        // get all post from API
        $response = Http::get(Config::get('constants.POSTS_URL'));

        // check if status code is 200
        if ($response->status() == 200) {
            $posts = $response->json();

            $result = array();
            $resultPosts = array();
            $resultUsers = array();

            // get all ocurrences by word in all title and body text
            $globalOcurrence = $this->globalOcurrences($posts);
            $globalOcurrenceTitle = $globalOcurrence["globalOcurrenceTitle"];
            $globalOcurrenceBody = $globalOcurrence["globalOcurrenceBody"];

            foreach ($posts as $key => $post) {
                $title = $post["title"];
                $body = $post["body"];
                $userId = $post["userId"];

                // get total points for a text
                $totalPointsTitle = $this->getPointsByOcurrences($title, $globalOcurrenceTitle, $globalOcurrenceBody);
                $totalPointsBody = $this->getPointsByOcurrences($body, $globalOcurrenceTitle, $globalOcurrenceBody);

                // get username form API and sum rating post for the user if exist, if not create the user
                $resultUsers = $this->updateOrCreateUser($userId, $resultUsers, ($totalPointsBody + $totalPointsTitle));

                // save the rating points for the post
                array_push(
                    $resultPosts,
                    array(
                        "idUser" => $post["userId"],
                        "idPost" => $post["id"],
                        "postRating" => ($totalPointsBody + $totalPointsTitle)
                    )
                );
            }

            // add require user parameters (userName, ratingUser) to final array
            $finalResult = $this->addUserToResultPosts($resultPosts, $resultUsers);
            // dd($finalResult);

            // sorted the final array
            $sorted = $this->orderBy($finalResult, 'postRating', SORT_DESC, 'userRating', SORT_DESC);
            // dd($sorted);

            $fileName = 'Rating Post.csv';
            $headers = array(
                "Content-type"        => "text/csv",
                "Content-Disposition" => "attachment; filename=$fileName",
                "Pragma"              => "no-cache",
                "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
                "Expires"             => "0"
            );

            $columns = array('Id Usuario', 'Nombre usuario', 'Valoración de usuario', 'Id Post', 'Valoración de post');

            $callback = function() use($sorted, $columns) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $columns);
                foreach ($sorted as $post) {
                    fputcsv($file, array($post['idUser'], $post['userName'], $post['userRating'], $post['idPost'], $post['postRating']));
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } else {

        }
    }

    /**
     * @description Get all ocurrences by word in all title and body text
     */
    function globalOcurrences ($posts) {
        $globalOcurrenceTitle = array();
        $globalOcurrenceBody = array();

        foreach ($posts as $key => $post) {
            $title = $post["title"];
            $body = $post["body"];

            // remove /n form string
            $title = preg_replace("/\r|\n/", " ", $title);
            $body = preg_replace("/\r|\n/", " ", $body);

            //
            $globalOcurrenceTitle = array_merge($globalOcurrenceTitle, explode(" ", $title));
            $globalOcurrenceBody = array_merge($globalOcurrenceBody, explode(" ", $body));
        }

        $globalOcurrenceTitle = array_count_values($globalOcurrenceTitle);
        $globalOcurrenceBody = array_count_values($globalOcurrenceBody);

        return array(
            "globalOcurrenceTitle" => $globalOcurrenceTitle,
            "globalOcurrenceBody" => $globalOcurrenceBody
        );
    }

    /**
     * @description Get total points for a text
     */
    function getPointsByOcurrences ($text, $globalOcurrenceTitle, $globalOcurrenceBody) {
        $totalPoints = 0;

        // remove /n form string
        $text = preg_replace("/\r|\n/", " ", $text);

        foreach (explode(" ", $text) as $key => $word) {
            $points = 0;
            if (array_key_exists($word, $globalOcurrenceTitle)) {
                $points += $globalOcurrenceTitle[$word] * 2;
            }
            if (array_key_exists($word, $globalOcurrenceBody)) {
                $points += $globalOcurrenceBody[$word];
            }
            $totalPoints += $points;
        }
        return $totalPoints;
    }

    /**
     * @description Get username form API and sum rating post for the user if exist, if not create the user
     */
    function updateOrCreateUser ($userId, $resultUsers, $points) {
        //
        if (array_key_exists($userId, $resultUsers)) {
            $resultUsers[$userId]["rating"] += $points;
        } else {
            $response = Http::get(Config::get('constants.USERS_URL'), ['id' => $userId]);

            // check if status code is 200
            if ($response->status() == 200) {
                $user = $response->json()[0];

                $resultUsers[$userId] = array(
                    "idUser" => $user["id"],
                    "userName" => $user["name"],
                    "rating" => $points
                );
            }
        }
        return $resultUsers;
    }

    /**
     * @description Add require user parameters (userName, ratingUser) to final array
     */
    function addUserToResultPosts ($resultPosts, $resultUsers) {
        foreach ($resultPosts as $key => $post) {
            $post["userName"] = $resultUsers[$post["idUser"]]["userName"];
            $post["userRating"] = $resultUsers[$post["idUser"]]["rating"];
            $resultPosts[$key] = $post;
        }
        return $resultPosts;
    }

    /**
     * @description Sorted array
     */
    function orderBy() {
        $args = func_get_args();
        $data = array_shift($args);
        foreach ($args as $n => $field) {
            if (is_string($field)) {
                $tmp = array();
                foreach ($data as $key => $row)
                    $tmp[$key] = $row[$field];
                    $args[$n] = $tmp;
            }
        }
        $args[] = &$data;
        call_user_func_array('array_multisort', $args);
        return array_pop($args);
    }

}
