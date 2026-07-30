<?php
/* Helper de roles GYM One */
function gymone_role($conn, $userid) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $st = $conn->prepare("SELECT role, is_boss FROM workers WHERE userid = ?");
    $st->bind_param("i", $userid);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$res) return $cache = null;
    $cache = $res['role'] ?: ($res['is_boss'] == 1 ? 'boss' : 'reception');
    return $cache;
}
function gymone_can($conn, $userid, array $roles) {
    return in_array(gymone_role($conn, $userid), $roles, true);
}
function gymone_require($conn, $userid, array $roles, $redirect) {
    if (!gymone_can($conn, $userid, $roles)) { header("Location: $redirect"); exit(); }
}
