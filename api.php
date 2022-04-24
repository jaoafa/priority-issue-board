<?php
ini_set('display_errors', 0);

require_once(__DIR__ . "/config.inc.php");

function initTable()
{
    global $pdo;
    // ユーザーテーブル
    // id: ユーザーID
    // ip: IPアドレス
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip VARCHAR(255) NOT NULL
    )");

    // 投票テーブル
    // id: 投票ユニークID
    // user_id: ユーザーID
    // issue_number: Issue Number
    // type: 投票タイプ (UP: u, DOWN: d)
    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        repo VARCHAR(255) NOT NULL,
        user_id INTEGER NOT NULL,
        issue_number INTEGER NOT NULL,
        type VARCHAR(1) NOT NULL
    )");
}

function getUserId()
{
    global $pdo;
    if (!isset($pdo)) {
        throw new ServerException("データベースの接続に失敗しました。");
    }

    $ip = isset($_SERVER["HTTP_CF_CONNECTING_IP"]) ? $_SERVER["HTTP_CF_CONNECTING_IP"] : $_SERVER["REMOTE_ADDR"];

    if (isset($_SESSION["user_id"])) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id");
        $stmt->bindValue(":id", $_SESSION["user_id"]);
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE ip = :ip");
        $stmt->bindValue(":ip", $ip);
        $stmt->execute();
    }
    $user = $stmt->fetch();
    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (ip) VALUES (:ip)");
        $stmt->bindValue(":ip", $ip);
        $stmt->execute();
        return $pdo->lastInsertId();
    }
    return $user["id"];
}

function fetchPublicRepos()
{
    global $config;
    $path = $config["file"]["repos"];
    $token = isset($config["githubToken"]) ? $config["githubToken"] : null;
    if (file_exists($path)) {
        $previous = json_decode(file_get_contents($path), true);

        if ($previous["timestamp"] + 60 * 5 <= time()) {
            return $previous["data"];
        }
    }
    // 5分以上経過している場合は再取得
    $headers = [
        "User-Agent: jaoafa/priority-issue-board",
        "Accept: application/vnd.github.v3+json"
    ];
    if ($token) {
        $headers[] = "Authorization: token $token";
    }

    $response = file_get_contents("https://api.github.com/orgs/jaoafa/repos?sort=updated&per_page=100", false, stream_context_create([
        "http" => [
            "header" => implode("\r\n", $headers),
            "ignore_errors" => true
        ]
    ]));
    preg_match('/HTTP\/1\.[0|1|x] ([0-9]{3})/', $http_response_header[0], $matches);
    $status_code = $matches[1];

    if ($status_code != 200) {
        if (isset($previous)) {
            return $previous["data"];
        }
        throw new ServerException("リポジトリ一覧の取得に失敗しました。");
    }
    $data = json_decode($response, true);
    $data = array_filter($data, function ($repo) {
        return !$repo["private"];
    });
    file_put_contents($path, json_encode([
            "timestamp" => time(),
            "data" => $data
        ]));
    return $data;
}

function fetchIssues($repo)
{
    global $config;

    if (file_exists($config["file"]["repos"])) {
        $repos = json_decode(file_get_contents($config["file"]["repos"]), true);
        $repos = array_map(function ($repo) {
            return $repo["name"];
        }, $repos["data"]);
        if (!in_array($repo, $repos)) {
            throw new UserException("対象リポジトリがパブリックではない可能性があるため、Issue を取得できません。");
        }
    }

    $path = $config["file"]["issues"];
    $token = isset($config["githubToken"]) ? $config["githubToken"] : null;
    $previous = [];
    if (file_exists($path)) {
        $previous = json_decode(file_get_contents($path), true);

        if (isset($previous[$repo]) && $previous[$repo]["timestamp"] + 60 * 5 <= time()) {
            return $previous[$repo]["data"];
        }
    }
    // 5分以上経過している場合は再取得
    $headers = [
        "User-Agent: jaoafa/priority-issue-board",
        "Accept: application/vnd.github.v3+json"
    ];
    if ($token) {
        $headers[] = "Authorization: token $token";
    }

    $response = file_get_contents("https://api.github.com/repos/jaoafa/$repo/issues?state=open&per_page=100", false, stream_context_create([
        "http" => [
            "header" => implode("\r\n", $headers),
            "ignore_errors" => true
        ]
    ]));
    preg_match('/HTTP\/1\.[0|1|x] ([0-9]{3})/', $http_response_header[0], $matches);
    $status_code = $matches[1];

    if ($status_code != 200) {
        if (isset($previous)) {
            return $previous["data"];
        }
        throw new ServerException("Issue の取得に失敗しました。");
    }
    $data = json_decode($response, true);
    $data = array_filter($data, function ($issue) {
        return !isset($issue["pull_request"]);
    });
    $output = array_merge($previous, [
        $repo => [
            "timestamp" => time(),
            "data" => $data
        ]
    ]);
    file_put_contents($path, json_encode($output));
    return $data;
}

function getVoted()
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM votes WHERE repo = :repo");
    $stmt->bindValue(":repo", $_GET["repo"]);
    $stmt->execute();

    $issueUpVotes = []; // issueNumber : [upVoteUserIds]
    $issueDownVotes = []; // issueNumber : [downVoteUserIds]
    while ($row = $stmt->fetch()) {
        $results[$row["issue_number"]] = $row["type"];

        $issueUpVotes[$row["issue_number"]] = isset($issueUpVotes[$row["issue_number"]]) ? $issueUpVotes[$row["issue_number"]] : [];
        $issueDownVotes[$row["issue_number"]] = isset($issueDownVotes[$row["issue_number"]]) ? $issueDownVotes[$row["issue_number"]] : [];

        if ($row["type"] === "u") {
            $issueUpVotes[$row["issue_number"]][] = $row["user_id"];
        } elseif ($row["type"] === "d") {
            $issueDownVotes[$row["issue_number"]][] = $row["user_id"];
        }
    }
    return [
        "issueUpVotes" => $issueUpVotes,
        "issueDownVotes" => $issueDownVotes
    ];
}

function vote($repo, $issue_number, $type)
{
    global $pdo;
    $user_id = getUserId();
    $stmt = $pdo->prepare("SELECT * FROM votes WHERE repo = :repo AND user_id = :user_id AND issue_number = :issue_number");
    $stmt->bindValue(":repo", $repo);
    $stmt->bindValue(":user_id", $user_id);
    $stmt->bindValue(":issue_number", $issue_number);
    $stmt->execute();
    $vote = $stmt->fetch();
    if ($type === null) {
        if (!$vote) {
            return;
        }
        $stmt = $pdo->prepare("DELETE FROM votes WHERE repo = :repo AND issue_number = :issue_number AND user_id = :user_id");
        $stmt->bindValue(":repo", $repo);
        $stmt->bindValue(":issue_number", $issue_number);
        $stmt->bindValue(":user_id", $user_id);
        $stmt->execute();
        return;
    }
    if ($vote) {
        $stmt = $pdo->prepare("UPDATE votes SET type = :type WHERE repo = :repo AND issue_number = :issue_number AND user_id = :user_id");
        $stmt->bindValue(":repo", $repo);
        $stmt->bindValue(":issue_number", $issue_number);
        $stmt->bindValue(":user_id", $user_id);
        $stmt->bindValue(":type", $type);
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("INSERT INTO votes (user_id, repo, issue_number, type) VALUES (:user_id, :repo, :issue_number, :type)");
        $stmt->bindValue(":repo", $repo);
        $stmt->bindValue(":user_id", $user_id);
        $stmt->bindValue(":issue_number", $issue_number);
        $stmt->bindValue(":type", $type);
        $stmt->execute();
    }
}

// 各アクションのサービス関数

function getRepos()
{
    $repos = fetchPublicRepos();
    usort($repos, function ($a, $b) {
        return ($a["open_issues"] < $b["open_issues"]) ? 1 : -1;
    });
    return array_map(function ($repo) {
        return $repo["name"];
    }, $repos);
}

function getIssues()
{
    $repo = $_GET["repo"];
    $userId = getUserId();
    $issues = fetchIssues($repo);
    $votes = getVoted();

    $results = [];
    foreach ($issues as $issue) {
        $issueUpVotes = isset($votes["issueUpVotes"][$issue["number"]]) ? $votes["issueUpVotes"][$issue["number"]] : [];
        $issueDownVotes = isset($votes["issueDownVotes"][$issue["number"]]) ? $votes["issueDownVotes"][$issue["number"]] : [];
        $results[] = [
            "number" => $issue["number"],
            "title" => $issue["title"],
            "author" => [
                "id" => $issue["user"]["id"],
                "name" => $issue["user"]["login"],
            ],
            "upvote" => [
                "voted" => in_array($userId, $issueUpVotes),
                "count" => count($issueUpVotes)
            ],
            "downvote" => [
                "voted" => in_array($userId, $issueDownVotes),
                "count" => count($issueDownVotes)
            ],
            "rank" => count($issueUpVotes) - count($issueDownVotes)
        ];
    }
    return $results;
}

function upVote()
{
    $repo = $_GET["repo"];
    $number = $_POST["number"];
    vote($repo, $number, "u");
}

function downVote()
{
    $repo = $_GET["repo"];
    $number = $_POST["number"];
    vote($repo, $number, "d");
}

function resetVote()
{
    $repo = $_GET["repo"];
    $number = $_POST["number"];
    vote($repo, $number, null);
}

function isIssueNumber($str)
{
    if (!is_numeric($str)) {
        return false;
    }
    return $str > 0;
}

function checkInput()
{
    // GET action は必須
    if (!isset($_GET["action"])) {
        throw new UserException("No action specified");
    }
    // GET repo は必須
    if (!isset($_GET["repo"])) {
        throw new UserException("No repo specified");
    }
    // GET repo には / が含まれてはいけない
    if (mb_strpos($_GET["repo"], "/") !== false) {
        throw new UserException("Invalid repo");
    }
    // POST number が数字でなければならない
    if (isset($_POST["number"])) {
        if (!isIssueNumber($_POST["number"])) {
            throw new UserException("Invalid issue number");
        }
    }
}

function main()
{
    // テーブル初期化
    initTable();

    // 入力確認
    checkInput();

    // GET ?action=get-issues : Issue の取得
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        if ($_GET["action"] === "get-repos") {
            return getRepos();
        }
        if ($_GET["action"] === "get-issues") {
            return getIssues();
        }
    }
    // POST ?action=up : +1 投票
    // POST ?action=down : -1 投票
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if ($_GET["action"] === "up") {
            return upVote();
        }
        if ($_GET["action"] === "down") {
            return downVote();
        }
        if ($_GET["action"] === "reset") {
            return resetVote();
        }
    }
    throw new UserException("Invalid action");
}

class ServerException extends Exception
{
    public function __construct($message)
    {
        parent::__construct($message);
    }
}

class UserException extends Exception
{
    public function __construct($message)
    {
        parent::__construct($message);
    }
}

try {
    header('Content-Type: application/json');

    $result = main();
    exit(json_encode([
        "status" => true,
        "data" => $result
    ]));
} catch (ServerException $e) {
    http_response_code(500);
    exit(json_encode([
        "status" => false,
        "type" => "ServerException",
        "trace" => explode("\n", $e->getTraceAsString()),
        "data" => $e->getMessage()
    ]));
} catch (UserException $e) {
    http_response_code(400);
    exit(json_encode([
        "status" => false,
        "type" => "UserException",
        "trace" => explode("\n", $e->getTraceAsString()),
        "data" => $e->getMessage()
    ]));
} catch (PDOException $e) {
    http_response_code(500);
    exit(json_encode([
        "status" => false,
        "type" => "PDOException",
        "trace" => explode("\n", $e->getTraceAsString()),
        "data" => $e->getMessage()
    ]));
} catch (Exception $e) {
    http_response_code(500);
    exit(json_encode([
        "status" => false,
        "type" => "Exception",
        "trace" => explode("\n", $e->getTraceAsString()),
        "data" => $e->getMessage()
    ]));
}