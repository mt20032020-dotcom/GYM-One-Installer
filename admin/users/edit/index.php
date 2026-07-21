<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
  header("Location: ../");
  exit();
}

$userid = $_SESSION['adminuser'];

function read_env_file($file_path)
{
  $env_file = file_get_contents($file_path);
  $env_lines = explode("\n", $env_file);
  $env_data = [];

  foreach ($env_lines as $line) {
    $line_parts = explode('=', $line);
    if (count($line_parts) == 2) {
      $key = trim($line_parts[0]);
      $value = trim($line_parts[1]);
      $env_data[$key] = $value;
    }
  }

  return $env_data;
}

$env_data = read_env_file('../../../.env');

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';
$currency = $env_data['CURRENCY'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../../../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
  die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
  die("Kapcsolódási hiba: " . $conn->connect_error);
}

$sql = "SELECT is_boss FROM workers WHERE userid = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userid);
$stmt->execute();
$stmt->store_result();

$is_boss = null;

if ($stmt->num_rows > 0) {
  $stmt->bind_result($is_boss);
  $stmt->fetch();
}
$stmt->close();

// API!
$file_path = 'https://api.gymoneglobal.com/latest/version.txt';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $file_path);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$latest_version = curl_exec($ch);
curl_close($ch);

$current_version = $version;

$alerts_html = "";

$is_new_version_available = version_compare($latest_version, $current_version) > 0;

if (isset($_GET['user']) && is_numeric($_GET['user'])) {
  $useridgymuser = $_GET['user'];

  $sql = "SELECT * FROM users WHERE userid = $useridgymuser";
require_once "/app/includes/cortesia_handler.php";
  $result = $conn->query($sql);

  if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstname = $row['firstname'];
    $lastname = $row['lastname'];
    $email = $row['email'];
    $cedula = $row['cedula'] ?? '';
    $celular = $row['celular'] ?? '';
    $regdate = $row['registration_date'];
    $lastlogin = $row['lastlogin'];
    $verify = $row['confirmed'];
    $lastip = $row['lastip'];
    // Eliminar plan futuro
    // Congelamiento
    if (isset($_POST['freeze_action']) && $_POST['freeze_action'] === 'freeze') {
        require_once '/app/includes/freezes.php';
        $fz_res = freeze_plan($conn, $useridgymuser, $_POST['freeze_start'] ?? '', $_POST['freeze_end'] ?? '', $_POST['freeze_reason'] ?? '', !empty($_POST['freeze_medical']), $_SESSION['userid'] ?? null);
        $freeze_msg = ($fz_res === true) ? 'OK' : $fz_res;
    }
    if (isset($_GET['unfreeze']) && is_numeric($_GET['unfreeze'])) {
        require_once '/app/includes/freezes.php';
        unfreeze_plan($conn, intval($_GET['unfreeze']));
        require_once '/app/iclock/lib/endtime.php';
        @sincronizar_acceso_speedface($useridgymuser);
        header('Location: ?user=' . $useridgymuser);
        exit();
    }
    // Beneficiarios: agregar por cedula
    if (isset($_POST['add_beneficiary_cedula'])) {
        require_once '/app/includes/beneficiaries.php';
        $ced_b = trim($_POST['add_beneficiary_cedula']);
        $stmt_b = $conn->prepare('SELECT userid FROM users WHERE cedula = ?');
        $stmt_b->bind_param('s', $ced_b);
        $stmt_b->execute();
        $row_b = $stmt_b->get_result()->fetch_assoc();
        if (!$row_b) {
            $beneficiary_msg = 'No existe un usuario con esa cedula. Debe registrarse primero.';
        } else {
            $is_repl = !empty($_POST['is_replacement']);
            $res_b = add_beneficiary($conn, $useridgymuser, $row_b['userid'], $is_repl);
            $beneficiary_msg = ($res_b === true) ? 'OK' : $res_b;
        }
    }
    // Beneficiarios: eliminar
    if (isset($_GET['remove_beneficiary']) && is_numeric($_GET['remove_beneficiary'])) {
        require_once '/app/includes/beneficiaries.php';
        remove_beneficiary($conn, $useridgymuser, intval($_GET['remove_beneficiary']));
        header('Location: ?user=' . $useridgymuser);
        exit();
    }
    if (isset($_GET['remove_future']) && is_numeric($_GET['remove_future'])) {
        require_once '/app/includes/future_plans.php';
        remove_future_plan($conn, intval($_GET['remove_future']), $useridgymuser);
        header('Location: ?user=' . $useridgymuser);
        exit();
    }
    $balance = $row['profile_balance'];
  } else {
    echo "The user does not exist!";
    exit;
  }
} else {
  echo "Incorrect request received!";
  exit;
}


if (isset($_POST['save'])) {
  $fields = ['firstname', 'lastname', 'email', 'cedula', 'celular'];
  if (isset($_POST['barrio'])) $_POST['city'] = trim($_POST['barrio']);
  $fields[] = 'city';
  $new_data = [];
  foreach ($fields as $field) {
    if (empty($_POST[$field])) {
      $alerts_html .= '<div class="alert alert-danger">Minden mező kitöltése kötelező.</div>';
      return;
    }
    $new_data[$field] = $_POST[$field];
  }

  $sql_old = "SELECT firstname, lastname, email, cedula, celular, city FROM users WHERE userid = ?";
  $stmt_old = $conn->prepare($sql_old);
  $stmt_old->bind_param("i", $useridgymuser);
  $stmt_old->execute();
  $result_old = $stmt_old->get_result()->fetch_assoc();
  $stmt_old->close();

  $changes = [];
  foreach ($fields as $field) {
    if ($result_old[$field] !== $new_data[$field]) {
      $changes["{$field}_old"] = $result_old[$field];
      $changes["{$field}_new"] = $new_data[$field];
    }
  }

  if (!empty($changes)) {
    $sql_update = "UPDATE users SET firstname = ?, lastname = ?, email = ?, cedula = ?, celular = ?, city = ? WHERE userid = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("ssssssi", $new_data['firstname'], $new_data['lastname'], $new_data['email'], $new_data['cedula'], $new_data['celular'], $new_data['city'], $useridgymuser);

    if ($stmt_update->execute()) {
      $stmt_update->close();

      $log_sql = "INSERT INTO logs (userid, action, actioncolor, details, time) VALUES (?, ?, ?, ?, NOW())";
      $stmt_log = $conn->prepare($log_sql);
      $action = $translations["success-edit-user"];
      $color = "info";
      $details = json_encode($changes, JSON_UNESCAPED_UNICODE);
      $stmt_log->bind_param("isss", $_SESSION['adminuser'], $action, $color, $details);
      $stmt_log->execute();
      $stmt_log->close();

      $alerts_html .= '<div class="alert alert-success">' . $translations["success-update"] . '</div>';
      header("Refresh: 1");
      exit;
    } else {
      $alerts_html .= '<div class="alert alert-danger">Unexpected error: ' . $conn->error . '</div>';
    }
  }
}


if (isset($_POST['delete_user'])) {
    $sql_get = "SELECT email FROM users WHERE userid = ?";
    $stmt_get = $conn->prepare($sql_get);
    $stmt_get->bind_param("i", $useridgymuser);
    $stmt_get->execute();
    $result_old = $stmt_get->get_result()->fetch_assoc();
    $stmt_get->close();

    if ($result_old) {
        $sql_delete = "DELETE FROM users WHERE userid = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $useridgymuser);

        if ($stmt_delete->execute()) {
            $stmt_delete->close();

            $changes = [
                "userid" => $useridgymuser,
                "email" => $result_old['email'],
                "balance" => $balance,
                "deleted_at" => date("Y-m-d H:i:s")
            ];

            $log_action = $translations['success-delete-user'];
            $log_color = 'danger';
            $log_details = json_encode($changes, JSON_UNESCAPED_UNICODE);

            $log_sql = "INSERT INTO logs (userid, action, actioncolor, details, time) VALUES (?, ?, ?, ?, NOW())";
            $stmt_log = $conn->prepare($log_sql);
            $stmt_log->bind_param("isss", $_SESSION['adminuser'], $log_action, $log_color, $log_details);
            $stmt_log->execute();
            $stmt_log->close();

            header("Location: ../");
            exit;
        } else {
            $alerts_html .= '<div class="alert alert-danger" role="alert">' . $translations["deletefail"] . '</div>';
        }
    } else {
        $alerts_html .= '<div class="alert alert-warning" role="alert">A felhasználó nem található.</div>';
    }

    $conn->close();
}


$today = date('Y-m-d');

$sql = "SELECT * FROM current_tickets WHERE userid = ? AND expiredate >= ? ORDER BY expiredate DESC LIMIT 1";

if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param("is", $useridgymuser, $today);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($row = $result->fetch_assoc()) {
    $ticket_name = $row['ticketname'];
    $ticket_buydate = $row['buydate'];
    $ticket_expiredate = $row['expiredate'];
    $ticket_opportunities = $row['opportunities'];

    $buyDate = new DateTime($ticket_buydate);
    $expireDate = new DateTime($ticket_expiredate);
    $todayDate = new DateTime($today);

    $ticket_total_days = $buyDate->diff($expireDate)->days;

    if ($todayDate <= $expireDate) {
      $ticket_remaining_days = $todayDate->diff($expireDate)->days;
    } else {
      $ticket_remaining_days = 0;
    }

    $ticket_remaining_percent = $ticket_total_days > 0
      ? round(($ticket_remaining_days / $ticket_total_days) * 100)
      : 0;
  } else {
    $ticket_name = null;
    $ticket_buydate = null;
    $ticket_expiredate = null;
    $ticket_opportunities = null;

    $ticket_total_days = 0;
    $ticket_remaining_days = 0;
    $ticket_remaining_percent = 0;
  }

  $translated_text = str_replace(
    ['{totalday}', '{leftday}'],
    [$ticket_total_days, $ticket_remaining_days],
    $translations['daytovalidity']
  );

  $stmt->close();
} else {
  echo "Hiba a lekérdezés előkészítésekor: " . $conn->error;
}



if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['userid'])) {

  $sql_update = "UPDATE users SET confirmed = 'Yes' WHERE userid = $useridgymuser";

  if ($conn->query($sql_update) === TRUE) {
    $alerts_html .= '<div class="alert alert-success" role="alert">' . $translations["regconfirm"] . '</div>';

    $action = $translations['regconfirm'] . ' ID: ' . $useridgymuser;
    $actioncolor = 'success';
    $sql = "INSERT INTO logs (userid, action, actioncolor, time) VALUES (?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $userid, $action, $actioncolor);
    $stmt->execute();

    header("Refresh:2");
    exit;
  } else {
    $alerts_html .= '<div class="alert alert-danger" role="alert">Unexpected error: ' . $conn->error . '</div>';
  }

  $conn->close();
}

?>




<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
  <meta charset="UTF-8">
  <title><?php echo $translations["dashboard"]; ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../../../assets/css/dashboard.css">
  <link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
</head>
<!-- ApexCharts -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<body>
  <nav class="navbar navbar-inverse visible-xs">
    <div class="container-fluid">
      <div class="navbar-header">
        <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#myNavbar">
          <span class="icon-bar"></span>
          <span class="icon-bar"></span>
          <span class="icon-bar"></span>
        </button>
        <a class="navbar-brand" href="#"><img src="../../../assets/img/logo.png" width="50px" alt="Logo"></a>
      </div>
      <div class="collapse navbar-collapse" id="myNavbar">
        <ul class="nav navbar-nav">
          <li><a href="../../dashboard"><i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?></a>
          </li>
          <li class="active"><a href="../"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a></li>
          <li><a href="../../statistics"><i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?></a>
          </li>
          <li><a href="../../boss/sell"><i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?></a></li>
          <li><a href="../../invoices"><i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?></a>
          </li>
          <?php if ($is_boss === 1) { ?>
            <li class="dropdown">
              <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="bi bi-gear"></i>
                <?php echo $translations["settings"]; ?> <span class="caret"></span></a>
              <ul class="dropdown-menu">
                <li><a href="../../boss/mainsettings"><?php echo $translations["businesspage"]; ?></a></li>
                <li><a href="../../boss/workers"><?php echo $translations["workers"]; ?></a></li>
                <li><a href="../../boss/packages"><?php echo $translations["packagepage"]; ?></a></li>
                <li><a href="../../boss/hours"><?php echo $translations["openhourspage"]; ?></a></li>
                <li><a href="../../boss/smtp"><?php echo $translations["mailpage"]; ?></a></li>
                <li><a href="../../boss/chroom"><?php echo $translations["chroompage"]; ?></a></li>
                <li><a href="../../boss/rule"><?php echo $translations["rulepage"]; ?></a></li>
              </ul>
            </li>
          <?php } ?>
          <li><a href="../../shop/tickets"><i class="bi bi-ticket"></i> <?php echo $translations["ticketspage"]; ?></a>
          </li>
          <li><a href="../../trainers/timetable"><i class="bi bi-calendar-event"></i>
              <?php echo $translations["timetable"]; ?></a></li>
          <li><a href="../../trainers/personal"><i class="bi bi-award"></i> <?php echo $translations["trainers"]; ?></a>
          </li>
          <?php if ($is_boss === 1) { ?>
            <li><a href="../../updater"><i class="bi bi-cloud-download"></i> <?php echo $translations["updatepage"]; ?>
                <?php if ($is_new_version_available): ?>
                  <span class="badge badge-warning"><i class="bi bi-exclamation-circle"></i></span>
                <?php endif; ?>
              </a></li>
          <?php } ?>
          <li><a href="../../log"><i class="bi bi-clock-history"></i> <?php echo $translations["logpage"]; ?></a></li>
        </ul>
      </div>
    </div>
  </nav>

  <div class="container-fluid">
    <div class="row content">
      <div class="col-sm-2 sidenav hidden-xs text-center">
        <h2><img src="../../../assets/img/logo.png" width="105px" alt="Logo"></h2>
        <p class="lead mb-4 fs-4"><?php echo $business_name ?> - <?php echo $version; ?></p>
        <ul class="nav nav-pills nav-stacked">
          <li class="sidebar-item">
            <a class="sidebar-link" href="../../dashboard/">
              <i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?>
            </a>
          </li>
          <li class="sidebar-item active">
            <a class="sidebar-link" href="#">
              <i class="bi bi-people"></i> <?php echo $translations["users"]; ?>
            </a>
          </li>
          <li class="sidebar-item">
            <a class="sidebar-link" href="../../statistics">
              <i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?>
            </a>
          </li>
          <li class="sidebar-item">
            <a class="sidebar-link" href="../../boss/sell">
              <i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?>
            </a>
          </li>
          <li class="sidebar-item">
            <a href="../../invoices/" class="sidebar-link">
              <i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?>
            </a>
          </li>
          <?php
          if ($is_boss === 1) {
            ?>
            <li class="sidebar-header">
              <?php echo $translations["settings"]; ?>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link" href="../../boss/mainsettings">
                <i class="bi bi-gear"></i>
                <span><?php echo $translations["businesspage"]; ?></span>
              </a>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link" href="../../boss/workers">
                <i class="bi bi-people"></i>
                <span><?php echo $translations["workers"]; ?></span>
              </a>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link" href="../../boss/packages">
                <i class="bi bi-box-seam"></i>
                <span><?php echo $translations["packagepage"]; ?></span>
              </a>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link" href="../../boss/hours">
                <i class="bi bi-clock"></i>
                <span><?php echo $translations["openhourspage"]; ?></span>
              </a>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link" href="../../boss/smtp">
                <i class="bi bi-envelope-at"></i>
                <span><?php echo $translations["mailpage"]; ?></span>
              </a>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link" href="../../boss/chroom">
                <i class="bi bi-duffle"></i>
                <span><?php echo $translations["chroompage"]; ?></span>
              </a>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link" href="../../boss/rule">
                <i class="bi bi-file-ruled"></i>
                <span><?php echo $translations["rulepage"]; ?></span>
              </a>
            </li>
            <?php
          }
          ?>
          <li class="sidebar-header">
            <?php echo $translations["shopcategory"]; ?>
          </li>
          <li class="sidebar-item">
            <!-- <a class="sidebar-ling" href="../shop/gateway">
                            <i class="bi bi-shield-lock"></i>
                            <span><?php echo $translations["gatewaypage"]; ?></span>
                        </a> -->
            <a class="sidebar-ling" href="../../shop/tickets">
              <i class="bi bi-ticket"></i>
              <span><?php echo $translations["ticketspage"]; ?></span>
            </a>
          </li>
          <li class="sidebar-header">
            <?php echo $translations["trainersclass"]; ?>
          </li>
          <li><a class="sidebar-link" href="../../trainers/timetable">
              <i class="bi bi-calendar-event"></i>
              <span><?php echo $translations["timetable"]; ?></span>
            </a></li>
          <li><a class="sidebar-link" href="../../trainers/personal">
              <i class="bi bi-award"></i>
              <span><?php echo $translations["trainers"]; ?></span>
            </a></li>
          <li class="sidebar-header"><?php echo $translations["other-header"]; ?></li>
          <?php
          if ($is_boss === 1) {
            ?>
            <li class="sidebar-item">
              <a class="sidebar-ling" href="../../updater">
                <i class="bi bi-cloud-download"></i>
                <span><?php echo $translations["updatepage"]; ?></span>
                <?php if ($is_new_version_available): ?>
                  <span class="sidebar-badge badge">
                    <i class="bi bi-exclamation-circle"></i>
                  </span>
                <?php endif; ?>
              </a>
            </li>
            <?php
          }
          ?>
          <li class="sidebar-item">
            <a class="sidebar-ling" href="../../log">
              <i class="bi bi-clock-history"></i>
              <span><?php echo $translations["logpage"]; ?></span>
            </a>
          </li>
        </ul><br>
      </div>
      <br>
      <div class="col-sm-10">
        
        <div class="row">
          <div class="col-sm-6">
            <div class="card shadow">
              <div class="card-heading">
                <h5 class="card-title"><?php echo $translations["editprofile"]; ?></h5>
              </div>
              <form method="POST">
                <div class="row">
                  <div class="col-12 col-lg-9 order-2 order-lg-1">
                    <div class="mb-3">
                      <div class="form-group">
                        <label for="firstname"><?php echo $translations["firstname"]; ?></label>
                        <input type="text" class="form-control" id="firstname" name="firstname"
                          value="<?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?>" required>
                      </div>
                    </div>
                    <div class="mb-3">
                      <div class="form-group">
                        <label for="lastname"><?php echo $translations["lastname"]; ?></label>
                        <input type="text" class="form-control" id="lastname" name="lastname"
                          value="<?php echo htmlspecialchars($lastname, ENT_QUOTES, 'UTF-8'); ?>" required>
                      </div>
                    </div>
                  </div>

                  <div class="col-12 col-lg-3 text-center order-1 order-lg-2 mb-3 mb-lg-0">
                    <?php
                    $profilePicPath = '../../../assets/img/profiles/' . $useridgymuser . '.png';
                    if (file_exists($profilePicPath)): ?>
                      <img src="<?php echo $profilePicPath; ?>" alt="User" class="img-rounded img-fluid"
                        style="max-height: 150px; width: auto;">
                    <?php endif; ?>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="form-group">
                    <label for="cedula">Cédula</label>
                    <input type="text" class="form-control" id="cedula" name="cedula" value="<?php echo htmlspecialchars($cedula, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Cédula">
                    <br>
                    <label for="celular">Celular</label>
                    <br>
                    <input type="tel" class="form-control" id="celular" name="celular" value="<?php echo htmlspecialchars($celular, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Celular">
                    <br>
                    <label for="barrio">Barrio</label>
                    <input type="text" class="form-control" id="barrio" name="barrio" value="<?php echo htmlspecialchars($row['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Barrio">
                    <br>
                    <label for="email"><?php echo $translations["email"]; ?></label>
                    <br>
                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                      required>
                  </div>

                </div>
                <div style="display:flex;gap:8px;flex-wrap:nowrap;align-items:center;">
                <button type="submit" name="save" class="btn btn-primary"><i class="bi bi-save"></i>
                  <?php echo $translations["save"]; ?></button>
                <?php
                if ($is_boss == 1) {
                  ?>
                  <?php require "/app/includes/cortesia_ui.php"; ?>
                  <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#deleteModal"
                    data-userid="1">
                    <i class="bi bi-trash"></i>
                    <?php echo $translations["deleteuserbtn"]; ?>
                  </button> <?php
                }
                ?>
                </div>

              </form>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card card-default">
              <div class="card-heading">
                <h5 class="card-title"><?php echo $translations["userinfo"]; ?></h5>
              </div>
              <div class="card-body">
                <div class="form-group">
                  <label for="registerInput"><?php echo $translations["reg-date"]; ?></label>
                  <input type="text" class="form-control" id="registerInput" value="<?php echo $regdate; ?>" disabled>
                </div>
                <div class="form-group">
                  <label for="lastLoginInput"><?php echo $translations["last-login"]; ?></label>
                  <input type="text" class="form-control" id="lastLoginInput" value="<?php echo $lastlogin; ?>"
                    disabled>
                </div>
                <div class="form-group">
                  <label for="Profile_balance"><?php echo $translations["profilebalance"]; ?></label>
                  <input type="text" class="form-control" id="Profile_balance"
                    value="<?php echo $balance; ?> <?php echo $currency; ?>" disabled>
                </div>
                <div class="form-group">
                  <label for="emailVerifiedInput"><?php echo $translations["regconfirm"]; ?></label>
                  <form method="post">
                    <div class="input-group">
                      <input type="text" class="form-control text-danger" id="emailVerifiedInput"
                        value="<?php echo ($verify == "Yes") ? $translations["yes"] : $translations["no"]; ?>" disabled>
                      <span class="input-group-btn">
                        <button class="btn btn-success" type="submit" <?php if ($verify == "Yes") {
                          echo "disabled";
                        } ?>>
                          <?php echo $translations["forceregconf"]; ?>
                        </button>
                        <input type="hidden" name="userid" value="<?php echo $useridgymuser; ?>">
                      </span>
                    </div>
                  </form>
                </div>
                <div class="form-group">
                  <label for="addressInput"><?php echo $translations["lastip"]; ?></label>
                  <input type="text" class="form-control" id="addressInput" value="<?php echo htmlspecialchars($lastip, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-md-6">
            <div class="panel panel-default">
              <div class="panel-heading text-center"
                style="background: rgb(9, 80, 220);
    background: -moz-linear-gradient(90deg, rgba(9, 80, 220, 1) 0%, rgba(9, 88, 210, 1) 50%, rgba(9, 110, 210, 1) 100%);
    background: -webkit-linear-gradient(90deg, rgba(9, 80, 220, 1) 0%, rgba(9, 88, 210, 1) 50%, rgba(9, 110, 210, 1) 100%);
    background: linear-gradient(90deg, rgba(9, 80, 220, 1) 0%, rgba(9, 88, 210, 1) 50%, rgba(9, 110, 210, 1) 100%);
    filter: progid:DXImageTransform.Microsoft.gradient(startColorstr=' #0950dc', endColorstr='#096ed2' , GradientType=1); color: white;">
                <div style="margin-bottom: 10px;">
                  <span class="label <?php
                  if (!isset($row) || $row === null) {
                    echo 'label-danger';
                  } else {
                    $expire = new DateTime($row['expiredate']);
                    $today = new DateTime(date('Y-m-d'));
                    $interval = $today->diff($expire)->format('%r%a');

                    if ($interval < 0) {
                      echo 'label-danger';
                    } elseif ($interval == 0) {
                      echo 'label-warning';
                    } else {
                      echo 'label-success';
                    }
                  }
                  ?>" style="font-size: 14px; padding: 8px 15px;">
                    <?php
                    if (!isset($row) || $row === null) {
                      echo '✗ ' . $translations["expired"];
                    } else {
                      $expire = new DateTime($row['expiredate']);
                      $today = new DateTime(date('Y-m-d'));
                      $interval = $today->diff($expire)->format('%r%a');

                      if ($interval < 0) {
                        echo '✗ ' . $translations["expired"];
                      } elseif ($interval == 0) {
                        echo $translations["expiresoon"];
                      } else {
                        echo '✓ ' . $translations["valid"];
                      }
                    }
                    ?>
                  </span>
                </div>
                <h4 style="margin: 0;"><?php echo $translations["status"]; ?></h4>
              </div>

              <div class="panel-body">
                <div class="row">
                  <div class="col-xs-12" style="margin-bottom: 15px;">
                    <div class="panel panel-default">
                      <div class="panel-body">
                        <div class="media">
                          <div class="media-left">
                            <div class="btn btn-success btn-circle"
                              style="width: 40px; height: 40px; border-radius: 50%; padding: 8px;">
                              📅
                            </div>
                          </div>
                          <div class="media-body">
                            <small class="text-muted"
                              style="text-transform: uppercase; font-weight: bold;"><?php echo $translations["buytime"]; ?></small>
                            <div style="font-weight: bold; font-size: 16px;"><?php echo $ticket_buydate; ?></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-xs-12" style="margin-bottom: 15px;">
                    <div class="panel panel-default">
                      <div class="panel-body">
                        <div class="media">
                          <div class="media-left">
                            <div class="btn btn-danger btn-circle"
                              style="width: 40px; height: 40px; border-radius: 50%; padding: 8px;">
                              🎯
                            </div>
                          </div>
                          <div class="media-body">
                            <small class="text-muted"
                              style="text-transform: uppercase; font-weight: bold;"><?php echo $translations["ticketspassname"]; ?></small>
                            <div style="font-weight: bold; font-size: 16px;"><?php echo $ticket_name; ?></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-xs-12" style="margin-bottom: 15px;">
                    <div class="panel panel-default">
                      <div class="panel-body">
                        <div class="media" style="margin-bottom: 15px;">
                          <div class="media-left">
                            <div class="btn btn-warning btn-circle"
                              style="width: 40px; height: 40px; border-radius: 50%; padding: 8px;">
                              ⏰
                            </div>
                          </div>
                          <div class="media-body">
                            <small class="text-muted"
                              style="text-transform: uppercase; font-weight: bold;"><?php echo $translations["validity"]; ?></small>
                            <div style="font-weight: bold; font-size: 16px;"><?php echo $translated_text; ?></div>
                          </div>
                        </div>
                        <div class="progress" style="margin-bottom: 10px;">
                          <div class="progress-bar <?php
                          echo ($ticket_remaining_percent < 20)
                            ? 'progress-bar-danger'
                            : (($ticket_remaining_percent < 40)
                              ? 'progress-bar-warning'
                              : 'progress-bar-info');
                          ?>" role="progressbar"
                            style="width: <?php echo $ticket_remaining_percent; ?>%">
                            <?php echo $ticket_remaining_percent; ?>%
                          </div>

                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-xs-12" style="margin-bottom: 15px;">
                    <div class="panel panel-default">
                      <div class="panel-body">
                        <div class="media" style="margin-bottom: 15px;">
                          <div class="media-left">
                            <div class="btn btn-info btn-circle"
                              style="width: 40px; height: 40px; border-radius: 50%; padding: 8px;">
                              💪
                            </div>
                          </div>
                          <div class="media-body">
                            <small class="text-muted"
                              style="text-transform: uppercase; font-weight: bold;"><?php echo $translations["tickettableoccassion"]; ?></small>
                            <div style="font-weight: bold; font-size: 16px;"><?php
                            echo is_null($ticket_opportunities) ? $translations["unlimited"] : $ticket_opportunities . ' ' . $translations["occassion_left"];
                            ?>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="row" style="margin-top:6px;">
          <div class="col-md-12">
            <?php
            require_once "/app/includes/future_plans.php";
            $future_plans = get_future_plans($conn, $useridgymuser);
            ?>
            <?php
            require_once "/app/includes/freezes.php";
            $fz_plan = freeze_get_active_plan($conn, $useridgymuser);
            $fz_current = is_frozen_today($conn, $useridgymuser);
            $fz_history = get_freezes($conn, $useridgymuser);
            $fz_used = $fz_plan ? has_frozen_this_plan($conn, $fz_plan["id"]) : false;
            ?>
            <div style="background:#fff;border-radius:12px;padding:20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
              <h4 style="font-weight:800; margin-bottom:12px;"><i class="bi bi-snow"></i> Congelamiento de plan
                <?php if ($fz_current): ?>
                <span class="badge" style="background:#0ea5e9;color:#fff;font-size:0.6em;vertical-align:middle;">CONGELADO</span>
                <?php endif; ?>
              </h4>
              <?php if (!empty($freeze_msg) && $freeze_msg === "OK"): ?>
                <div class="alert alert-success" style="padding:8px;font-size:0.9em;">Plan congelado correctamente. El vencimiento fue extendido.</div>
              <?php elseif (!empty($freeze_msg)): ?>
                <div class="alert alert-warning" style="padding:8px;font-size:0.9em;"><?php echo htmlspecialchars($freeze_msg); ?></div>
              <?php endif; ?>
              <?php if ($fz_current): ?>
                <div style="background:#e0f2fe;border:1px solid #7dd3fc;border-radius:10px;padding:14px;margin-bottom:12px;">
                  <i class="bi bi-snow2" style="color:#0284c7;"></i>
                  <strong>Plan congelado</strong> del <?php echo date("d/m/Y", strtotime($fz_current["freeze_start"])); ?>
                  al <?php echo date("d/m/Y", strtotime($fz_current["freeze_end"])); ?>
                  (<?php echo $fz_current["days_frozen"]; ?> días)<br>
                  <small style="color:#666;">Motivo: <?php echo htmlspecialchars($fz_current["reason"]); ?>
                  <?php if ($fz_current["has_medical"]): ?> — <i class="bi bi-file-medical"></i> Con incapacidad médica<?php endif; ?></small><br>
                  <a href="?user=<?php echo $useridgymuser; ?>&unfreeze=<?php echo $fz_current["id"]; ?>"
                     class="btn btn-warning btn-sm" style="margin-top:8px;"
                     onclick="return confirm('¿Cancelar el congelamiento? Se revertirán los días restantes del vencimiento.');">
                    <i class="bi bi-sun"></i> Descongelar ahora
                  </a>
                </div>
              <?php elseif ($fz_plan && !$fz_used): ?>
                <form method="POST">
                  <input type="hidden" name="freeze_action" value="freeze">
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                      <label style="font-size:0.85em;">Desde</label>
                      <input type="date" name="freeze_start" class="form-control" required min="<?php echo date("Y-m-d"); ?>">
                    </div>
                    <div>
                      <label style="font-size:0.85em;">Hasta</label>
                      <input type="date" name="freeze_end" class="form-control" required min="<?php echo date("Y-m-d"); ?>">
                    </div>
                  </div>
                  <div style="margin-bottom:10px;">
                    <label style="font-size:0.85em;">Motivo</label>
                    <input type="text" name="freeze_reason" class="form-control" placeholder="Ej: Viaje de trabajo, incapacidad médica..." required>
                  </div>
                  <div style="margin-bottom:12px;">
                    <label style="font-weight:normal;font-size:0.9em;cursor:pointer;">
                      <input type="checkbox" name="freeze_medical" value="1"> Tiene incapacidad médica (sin límite de días)
                    </label>
                    <small style="display:block;color:#999;font-size:0.78em;">Sin incapacidad el máximo es 7 días. El plan se extiende por los días congelados.</small>
                  </div>
                  <button type="submit" class="btn btn-info"><i class="bi bi-snow"></i> Congelar plan</button>
                </form>
              <?php elseif ($fz_plan && $fz_used): ?>
                <p style="color:#999;margin:0;"><i class="bi bi-info-circle"></i> Este plan ya usó su congelamiento (1 por vigencia). Podrá congelar de nuevo cuando renueve.</p>
              <?php else: ?>
                <p style="color:#999;margin:0;"><i class="bi bi-info-circle"></i> El usuario no tiene un plan activo para congelar.</p>
              <?php endif; ?>
              <?php if (!empty($fz_history)): ?>
                <details style="margin-top:12px;">
                  <summary style="cursor:pointer;font-size:0.85em;color:#666;">Historial de congelamientos (<?php echo count($fz_history); ?>)</summary>
                  <table class="table table-striped" style="margin-top:8px;font-size:0.85em;">
                    <thead><tr><th>Desde</th><th>Hasta</th><th>Días</th><th>Motivo</th><th>Médica</th></tr></thead>
                    <tbody>
                      <?php foreach ($fz_history as $fh): ?>
                      <tr>
                        <td><?php echo date("d/m/Y", strtotime($fh["freeze_start"])); ?></td>
                        <td><?php echo date("d/m/Y", strtotime($fh["freeze_end"])); ?></td>
                        <td><?php echo $fh["days_frozen"]; ?></td>
                        <td><?php echo htmlspecialchars($fh["reason"]); ?></td>
                        <td><?php echo $fh["has_medical"] ? "Sí" : "No"; ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </details>
              <?php endif; ?>
            </div>
            <?php
            require_once "/app/includes/beneficiaries.php";
            $tiq_activa = get_active_tiquetera($conn, $useridgymuser);
            if ($tiq_activa):
                $benefs = get_beneficiaries($conn, $useridgymuser);
                $puede_cambiar = can_change_beneficiary($conn, $useridgymuser);
            ?>
            <div style="background:#fff;border-radius:12px;padding:20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
              <h4 style="font-weight:800; margin-bottom:12px;"><i class="bi bi-people"></i> Beneficiarios de la tiquetera
                <span class="badge" style="background:#0ea5e9;color:#fff;font-size:0.6em;vertical-align:middle;"><?php echo count($benefs); ?>/2</span>
              </h4>
              <p style="font-size:0.85em;color:#888;margin-bottom:12px;">
                <i class="bi bi-info-circle"></i> Tiquetera: <strong><?php echo htmlspecialchars($tiq_activa["ticketname"]); ?></strong> — vence <?php echo date("d/m/Y", strtotime($tiq_activa["expiredate"])); ?>.
                Cambio de beneficiario disponible: <strong style="color:<?php echo $puede_cambiar ? "#16a34a" : "#dc2626"; ?>;"><?php echo $puede_cambiar ? "Sí" : "Ya usado en esta vigencia"; ?></strong>
              </p>
              <?php if (!empty($beneficiary_msg) && $beneficiary_msg !== "OK"): ?>
                <div class="alert alert-warning" style="padding:8px;font-size:0.9em;"><?php echo htmlspecialchars($beneficiary_msg); ?></div>
              <?php elseif (!empty($beneficiary_msg)): ?>
                <div class="alert alert-success" style="padding:8px;font-size:0.9em;">Beneficiario agregado correctamente.</div>
              <?php endif; ?>
              <?php if (empty($benefs)): ?>
                <p style="color:#999;">Sin beneficiarios registrados.</p>
              <?php else: ?>
                <table class="table table-striped" style="margin-bottom:12px;">
                  <thead><tr><th>Nombre</th><th>Cédula</th><th>Desde</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($benefs as $b): ?>
                    <tr>
                      <td><strong><?php echo htmlspecialchars($b["lastname"] . " " . $b["firstname"]); ?></strong></td>
                      <td><?php echo htmlspecialchars($b["cedula"]); ?></td>
                      <td><?php echo date("d/m/Y", strtotime($b["created_at"])); ?></td>
                      <td>
                        <a href="?user=<?php echo $useridgymuser; ?>&remove_beneficiary=<?php echo $b["beneficiary_userid"]; ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('¿Quitar este beneficiario? Recuerda: solo se permite 1 cambio por vigencia.');">
                          <i class="bi bi-trash"></i>
                        </a>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
              <?php if (count($benefs) < 2): ?>
              <div style="position:relative;">
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
                  <input type="text" id="benefSearch" class="form-control" placeholder="Buscar por cédula o nombre..." style="max-width:280px;" autocomplete="off">
                  <?php if (!empty($benefs)): ?>
                  <label style="font-weight:normal;font-size:0.85em;margin:0;"><input type="checkbox" id="benefIsRepl"> Es reemplazo</label>
                  <?php endif; ?>
                  <button type="button" class="btn btn-success btn-sm" onclick="abrirModalNuevoBenef()"><i class="bi bi-person-plus-fill"></i> Crear nuevo</button>
                </div>
                <div id="benefResults" style="display:none;position:absolute;top:100%;left:0;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:100;max-width:280px;width:100%;max-height:220px;overflow-y:auto;"></div>
              </div>
              <!-- Form oculto para enviar la seleccion -->
              <form method="POST" id="benefHiddenForm" style="display:none;">
                <input type="hidden" name="add_beneficiary_cedula" id="benefSelectedCedula">
                <input type="hidden" name="is_replacement" id="benefHiddenRepl" value="">
              </form>
              <!-- Modal crear nuevo -->
              <div id="modalNuevoBenef" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9998;justify-content:center;align-items:center;">
                <div style="background:#fff;border-radius:14px;padding:24px;max-width:480px;width:92%;max-height:90vh;overflow-y:auto;">
                  <h4 style="margin-top:0;"><i class="bi bi-person-plus"></i> Registrar beneficiario nuevo</h4>
                  <div id="nbError" style="display:none;" class="alert alert-danger"></div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                    <input type="text" id="nbFirstname" class="form-control" placeholder="Nombre *">
                    <input type="text" id="nbLastname" class="form-control" placeholder="Apellido *">
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                    <input type="text" id="nbCedula" class="form-control" placeholder="Cédula *">
                    <input type="tel" id="nbCelular" class="form-control" placeholder="Celular">
                  </div>
                  <div style="margin-bottom:8px;">
                    <input type="text" id="nbBarrio" class="form-control" placeholder="Barrio *">
                  </div>
                  <input type="email" id="nbEmail" class="form-control" placeholder="Correo (opcional)" style="margin-bottom:8px;">
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                    <select id="nbGender" class="form-control">
                      <option value="Male">Masculino</option>
                      <option value="Female">Femenino</option>
                      <option value="Other">Otro</option>
                    </select>
                    <input type="date" id="nbBirthdate" class="form-control">
                  </div>
                  <div style="margin-bottom:12px;">
                    <button type="button" class="btn btn-default btn-sm" onclick="nbAbrirCamara()"><i class="bi bi-camera"></i> Tomar foto</button>
                    <span id="nbFotoStatus" style="font-size:0.85em;color:#999;margin-left:8px;">Sin foto</span>
                    <small style="display:block;color:#888;font-size:0.75em;margin-top:3px;"><i class="bi bi-info-circle"></i> Foto para ingreso con reconocimiento facial.</small>
                  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px;margin-top:8px;font-size:0.8em;color:#1e40af;">
                    <i class="bi bi-key"></i> La contraseña inicial será su <strong>número de cédula</strong>. Podrá cambiarla desde su perfil.
                  </div>
                  </div>
                  <div id="nbCamModal" style="display:none;margin-bottom:12px;text-align:center;">
                    <video id="nbCamVideo" autoplay playsinline style="max-width:100%;border-radius:10px;"></video>
                    <canvas id="nbCamCanvas" style="display:none;"></canvas>
                    <div style="margin-top:8px;">
                      <button type="button" class="btn btn-danger btn-sm" onclick="nbCapturar()"><i class="bi bi-camera-fill"></i> Capturar</button>
                      <button type="button" class="btn btn-default btn-sm" onclick="nbCerrarCamara()">Cancelar</button>
                    </div>
                  </div>
                  <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" class="btn btn-default" onclick="cerrarModalNuevoBenef()">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="nbGuardar()"><i class="bi bi-check-lg"></i> Registrar y agregar</button>
                  </div>
                </div>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <div style="background:#fff;border-radius:12px;padding:20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
              <h4 style="font-weight:800; margin-bottom:12px;"><i class="bi bi-calendar-plus"></i> Planes futuros
                <span class="badge" style="background:#7c3aed;color:#fff;font-size:0.6em;vertical-align:middle;"><?php echo count($future_plans); ?> en cola</span>
              </h4>
              <?php if (empty($future_plans)): ?>
                <p style="color:#999;margin:0;">No hay planes futuros en cola.</p>
              <?php else: ?>
                <table class="table table-striped" style="margin:0;">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Plan</th>
                      <th>Comprado</th>
                      <th>Inicio estimado</th>
                      <th>Fin estimado</th>
                      <th>Ocasiones</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($future_plans as $idx => $fp): ?>
                    <tr>
                      <td><?php echo $idx + 1; ?></td>
                      <td><strong><?php echo htmlspecialchars($fp["ticketname"]); ?></strong></td>
                      <td><?php echo date("d/m/Y", strtotime($fp["purchase_date"])); ?></td>
                      <td>
                        <?php echo date("d/m/Y", strtotime($fp["estimated_start"])); ?>
                        <?php if ($fp["desired_start_date"]): ?>
                          <br><small style="color:#7c3aed;"><i class="bi bi-pin"></i> Fecha elegida</small>
                        <?php endif; ?>
                      </td>
                      <td><?php echo date("d/m/Y", strtotime($fp["estimated_end"])); ?></td>
                      <td><?php echo $fp["opportunities"] ? $fp["opportunities"] : "Ilimitado"; ?></td>
                      <td>
                        <a href="?user=<?php echo $useridgymuser; ?>&remove_future=<?php echo $fp["id"]; ?>" 
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('¿Eliminar este plan de la cola?');">
                          <i class="bi bi-trash"></i>
                        </a>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
            <div class="card" style="border-radius:12px; padding:20px;">
              <h4 style="font-weight:800; margin-bottom:4px;"><i class="bi bi-clock-history"></i> Historial de accesos</h4>
              <?php
              $visitas_mes = 0;
              $rVm = $conn->query("SELECT COUNT(*) t FROM access_log WHERE userid = " . (int)$useridgymuser . " AND is_companion = 0 AND entry_time >= '" . date('Y-m-01') . "'");
              if ($rVm) { $visitas_mes = (int)$rVm->fetch_assoc()['t']; }
              echo '<p style="color:#71717a; margin-bottom:12px;">Visitas del titular este mes: <strong>' . $visitas_mes . '</strong></p>';
              $rH = $conn->query("SELECT display_name, is_companion, entry_time FROM access_log WHERE userid = " . (int)$useridgymuser . " ORDER BY id DESC LIMIT 15");
              if ($rH && $rH->num_rows > 0) {
                  echo '<table class="table table-striped" style="margin-bottom:0;"><thead><tr><th>Fecha y hora</th><th>Qui&eacute;n ingres&oacute;</th></tr></thead><tbody>';
                  while ($h = $rH->fetch_assoc()) {
                      $badge = $h['is_companion'] ? ' <span style="background:#f4f4f5;color:#71717a;border-radius:6px;padding:2px 8px;font-size:.8em;">acompa&ntilde;ante</span>' : ' <span style="background:#fee2e2;color:#b91c1c;border-radius:6px;padding:2px 8px;font-size:.8em;">titular</span>';
                      echo '<tr><td style="white-space:nowrap;">' . date('d/m/Y H:i', strtotime($h['entry_time'])) . '</td><td>' . htmlspecialchars($h['display_name']) . $badge . '</td></tr>';
                  }
                  echo '</tbody></table>';
              } else {
                  echo '<p style="color:#a1a1aa;">A&uacute;n no hay accesos registrados.</p>';
              }
              ?>
            </div>
          </div>
        </div>
        
  </div>

  <!-- EXIT MODAL -->
  <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="margin-top: 100px;">
      <div class="modal-content" style="border: none; box-shadow: 0 0 40px rgba(0,0,0,.2);">
        <div class="modal-body text-center" style="padding: 40px;">

          <div style="margin-bottom: 25px;">
            <div style="width: 80px; height: 80px; margin: 0 auto;
                                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                                border-radius: 50%;
                                display: flex; align-items: center; justify-content: center;">
              <i class="bi bi-box-arrow-right" style="color: #fff; font-size: 40px;"></i>
            </div>
          </div>

          <h4 style="font-weight: bold; margin-bottom: 15px;">
            <p><?php echo $translations["exit-modal"]; ?></p>
          </h4>

          <div class="text-center">
            <a type="button" class="btn btn-default" data-dismiss="modal"
              style="padding: 8px 25px; margin-right: 10px;">
              <i class="bi bi-x-circle" style="margin-right: 5px;"></i>
              <?php echo $translations["not-yet"]; ?>
            </a>

            <a href="../../logout.php" type="button" class="btn btn-danger" style="padding: 8px 25px;">
              <i class="bi bi-check-circle" style="margin-right: 5px;"></i>
              <?php echo $translations["confirm"]; ?>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <!-- DELETE USER MODAL -->

  <!-- Modal -->
  <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel"
    aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteModalLabel"><?php echo $translations["deleteuserbtn"]; ?></h5>
        </div>
        <div class="modal-body">
          <p><?php echo $translations["undoallert"]; ?></p>
          <code><?php echo $firstname; ?> <?php echo $lastname; ?> <?php echo $translations["identifier"]; ?> <?php echo $useridgymuser; ?></code>
        </div>
        <div class="modal-footer">
          <form method="post" action="">
            <input type="hidden" name="userid" id="userid" value="<?php echo $useridgymuser; ?>">
            <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="bi bi-x-lg"></i>
              <?php echo $translations["not-yet"]; ?></button>
            <button type="submit" name="delete_user" class="btn btn-danger"><i class="bi bi-exclamation-triangle"></i>
              <?php echo $translations["delete"]; ?></button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- SCRIPTS! -->
  <script src="../../../assets/js/date-time.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
<script>
// ===== BENEFICIARIOS: buscador en vivo =====
(function(){
  var inp = document.getElementById("benefSearch");
  if (!inp) return;
  var box = document.getElementById("benefResults");
  var timer = null;
  inp.addEventListener("input", function(){
    clearTimeout(timer);
    var q = inp.value.trim();
    if (q.length < 2) { box.style.display = "none"; return; }
    timer = setTimeout(function(){
      fetch("beneficiary_api.php?action=search&q=" + encodeURIComponent(q))
        .then(r => r.json())
        .then(function(list){
          if (!list.length) { box.innerHTML = "<div style='padding:10px;color:#999;font-size:0.85em;'>Sin resultados — usa Crear nuevo</div>"; box.style.display = "block"; return; }
          box.innerHTML = list.map(function(u){
            return "<div onclick='seleccionarBenef(\"" + u.cedula + "\")' style='padding:10px;cursor:pointer;border-bottom:1px solid #eee;' onmouseover='this.style.background=\"#f5f5f5\"' onmouseout='this.style.background=\"#fff\"'>" +
              "<strong>" + u.lastname + " " + u.firstname + "</strong><br><small style='color:#888;'>CC " + u.cedula + "</small></div>";
          }).join("");
          box.style.display = "block";
        });
    }, 300);
  });
  document.addEventListener("click", function(e){
    if (!box.contains(e.target) && e.target !== inp) box.style.display = "none";
  });
})();

function seleccionarBenef(cedula) {
  document.getElementById("benefSelectedCedula").value = cedula;
  var repl = document.getElementById("benefIsRepl");
  document.getElementById("benefHiddenRepl").value = (repl && repl.checked) ? "1" : "";
  document.getElementById("benefHiddenForm").submit();
}

// ===== Modal crear nuevo =====
function abrirModalNuevoBenef() { document.getElementById("modalNuevoBenef").style.display = "flex"; }
function cerrarModalNuevoBenef() { nbCerrarCamara(); document.getElementById("modalNuevoBenef").style.display = "none"; }

var nbStream = null;
var nbFoto = "";

function nbAbrirCamara() {
  document.getElementById("nbCamModal").style.display = "block";
  navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } })
    .then(function(s){ nbStream = s; document.getElementById("nbCamVideo").srcObject = s; })
    .catch(function(e){ alert("No se pudo acceder a la cámara: " + e.message); document.getElementById("nbCamModal").style.display = "none"; });
}

function nbCapturar() {
  var v = document.getElementById("nbCamVideo");
  var c = document.getElementById("nbCamCanvas");
  c.width = v.videoWidth; c.height = v.videoHeight;
  c.getContext("2d").drawImage(v, 0, 0);
  nbFoto = c.toDataURL("image/jpeg", 0.85);
  document.getElementById("nbFotoStatus").innerHTML = "<span style='color:#16a34a;'><i class='bi bi-check-circle'></i> Foto lista</span>";
  nbCerrarCamara();
}

function nbCerrarCamara() {
  if (nbStream) { nbStream.getTracks().forEach(t => t.stop()); nbStream = null; }
  document.getElementById("nbCamModal").style.display = "none";
}

function nbGuardar() {
  var err = document.getElementById("nbError");
  err.style.display = "none";
  var datos = new FormData();
  datos.append("action", "create");
  datos.append("firstname", document.getElementById("nbFirstname").value.trim());
  datos.append("lastname", document.getElementById("nbLastname").value.trim());
  datos.append("cedula", document.getElementById("nbCedula").value.trim());
  datos.append("celular", document.getElementById("nbCelular").value.trim());
  datos.append("barrio", document.getElementById("nbBarrio").value.trim());
  datos.append("email", document.getElementById("nbEmail").value.trim());
  datos.append("gender", document.getElementById("nbGender").value);
  datos.append("birthdate", document.getElementById("nbBirthdate").value);
  datos.append("face_photo", nbFoto);

  fetch("beneficiary_api.php", { method: "POST", body: datos })
    .then(r => r.json())
    .then(function(res){
      if (res.error) { err.textContent = res.error; err.style.display = "block"; return; }
      // Creado: ahora agregarlo como beneficiario via el form
      seleccionarBenef(res.cedula);
    })
    .catch(function(){ err.textContent = "Error de conexión."; err.style.display = "block"; });
}
</script>
</body>

</html>