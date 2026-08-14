<?php function read_env_file($file_path)
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
$copyright_year = date("Y");
$alerts_html = '';


$env_data = read_env_file('../.env');

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';
$country = $env_data['COUNTRY'] ?? '';
$street = $env_data['STREET'] ?? '';
$city = $env_data['CITY'] ?? '';
$hause_no = $env_data['HOUSE_NUMBER'] ?? '';
$description = $env_data['DESCRIPTION'] ?? '';
$metakey = $env_data['META_KEY'] ?? '';
$gkey = $env_data['GOOGLE_KEY'] ?? '';
$mailadress = $env_data['MAIL_USERNAME'] ?? '';
$phoneno = $env_data['PHONE_NO'] ?? '';
$smtp_host = $env_data['MAIL_HOST'] ?? '';
$smtp_port = $env_data['MAIL_PORT'] ?? '';
$smtp_encryption = $env_data['MAIL_ENCRYPTION'] ?? '';
$smtp_username = $env_data['MAIL_USERNAME'] ?? '';
$smtp_password = $env_data['MAIL_PASSWORD'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../assets/lang/";

$langFile = $langDir . "$lang.json";

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$domain_url = $protocol . $host;

if (!file_exists($langFile)) {
  die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
  die("Kapcsolódási hiba: " . $conn->connect_error);
}

$dayNames = [
  1 => $translations["Mon"],
  2 => $translations["Tue"],
  3 => $translations["Wed"],
  4 => $translations["Thu"],
  5 => $translations["Fri"],
  6 => $translations["Sat"],
  7 => $translations["Sun"]
];

$days = [];
$result = $conn->query("SELECT * FROM opening_hours ORDER BY day ASC");
while ($row = $result->fetch_assoc()) {
  $days[] = $row;
}

$today = new DateTime('today');
$maxDate = (new DateTime('today'))->modify('+14 days');

$todayStr = $today->format('Y-m-d');
$maxDateStr = $maxDate->format('Y-m-d');

$exceptions = [];
$stmt = $conn->prepare("
    SELECT * 
    FROM opening_hours_exceptions 
    WHERE date BETWEEN ? AND ?
    ORDER BY date ASC
");
$stmt->bind_param("ss", $todayStr, $maxDateStr);
$stmt->execute();

$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $exceptions[] = $row;
}
$stmt->close();

$months = [
  1 => $translations["Jan"],
  2 => $translations["Feb"],
  3 => $translations["Mar"],
  4 => $translations["Apr"],
  5 => $translations["May"],
  6 => $translations["Jun"],
  7 => $translations["Jul"],
  8 => $translations["Aug"],
  9 => $translations["Sep"],
  10 => $translations["Oct"],
  11 => $translations["Nov"],
  12 => $translations["Dec"],
];

require_once "/app/includes/mailer.php";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $name = $_POST['name'];
  $userEmail = $_POST['email'];
  $userMessage = $_POST['message'];

  

  $env_data = ['MAIL_HOST'=>$smtp_host,'MAIL_PORT'=>$smtp_port,'MAIL_USERNAME'=>$smtp_username,'MAIL_PASSWORD'=>$smtp_password,'MAIL_ENCRYPTION'=>$smtp_encryption];
  $adminBody = $translations["fullname"] . ": " . $name . "\n" . $translations["email"] . ": " . $userEmail . "\n" . $translations["message"] . ": " . $userMessage . "\n";
  $result = send_mail($env_data, $smtp_username, $translations["newmessagefromwebsite"], $adminBody, $name);
  $editedcontent = <<<EOD
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html data-editor-version="2" class="sg-campaigns" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1">
  <!--[if !mso]><!-->
  <meta http-equiv="X-UA-Compatible" content="IE=Edge">
  <!--<![endif]-->
  <!--[if (gte mso 9)|(IE)]>
  <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
  </xml>
  <![endif]-->
  <!--[if (gte mso 9)|(IE)]>
<style type="text/css">
body {width: 600px;margin: 0 auto;}
table {border-collapse: collapse;}
table, td {mso-table-lspace: 0pt;mso-table-rspace: 0pt;}
img {-ms-interpolation-mode: bicubic;}
</style>
<![endif]-->
  <style type="text/css">
body, p, div {
  font-family: arial,helvetica,sans-serif;
  font-size: 14px;
}
body {
  color: #000000;
}
body a {
  color: #1188E6;
  text-decoration: none;
}
p { margin: 0; padding: 0; }
table.wrapper {
  width:100% !important;
  table-layout: fixed;
  -webkit-font-smoothing: antialiased;
  -webkit-text-size-adjust: 100%;
  -moz-text-size-adjust: 100%;
  -ms-text-size-adjust: 100%;
}
img.max-width {
  max-width: 100% !important;
}
.column.of-2 {
  width: 50%;
}
.column.of-3 {
  width: 33.333%;
}
.column.of-4 {
  width: 25%;
}
ul ul ul ul  {
  list-style-type: disc !important;
}
ol ol {
  list-style-type: lower-roman !important;
}
ol ol ol {
  list-style-type: lower-latin !important;
}
ol ol ol ol {
  list-style-type: decimal !important;
}
@media screen and (max-width:480px) {
  .preheader .rightColumnContent,
  .footer .rightColumnContent {
    text-align: left !important;
  }
  .preheader .rightColumnContent div,
  .preheader .rightColumnContent span,
  .footer .rightColumnContent div,
  .footer .rightColumnContent span {
    text-align: left !important;
  }
  .preheader .rightColumnContent,
  .preheader .leftColumnContent {
    font-size: 80% !important;
    padding: 5px 0;
  }
  table.wrapper-mobile {
    width: 100% !important;
    table-layout: fixed;
  }
  img.max-width {
    height: auto !important;
    max-width: 100% !important;
  }
  a.bulletproof-button {
    display: block !important;
    width: auto !important;
    font-size: 80%;
    padding-left: 0 !important;
    padding-right: 0 !important;
  }
  .columns {
    width: 100% !important;
  }
  .column {
    display: block !important;
    width: 100% !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
  }
  .social-icon-column {
    display: inline-block !important;
  }
}
</style>
  <!--user entered Head Start--><!--End Head user entered-->
</head>
<body>
  <center class="wrapper" data-link-color="#1188E6" data-body-style="font-size:14px; font-family:arial,helvetica,sans-serif; color:#000000; background-color:#FFFFFF;">
    <div class="webkit">
      <table cellpadding="0" cellspacing="0" border="0" width="100%" class="wrapper" bgcolor="#FFFFFF">
        <tr>
          <td valign="top" bgcolor="#FFFFFF" width="100%">
            <table width="100%" role="content-container" class="outer" align="center" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="100%">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td>
                        <!--[if mso]>
<center>
<table><tr><td width="600">
<![endif]-->
                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;" align="center">
                                  <tr>
                                    <td role="modules-container" style="padding:0px 0px 0px 0px; color:#000000; text-align:left;" bgcolor="#FFFFFF" width="100%" align="left"><table class="module preheader preheader-hide" role="module" data-type="preheader" border="0" cellpadding="0" cellspacing="0" width="100%" style="display: none !important; mso-hide: all; visibility: hidden; opacity: 0; color: transparent; height: 0; width: 0;">
<tr>
  <td role="module-content">
    <p></p>
  </td>
</tr>
</table><table border="0" cellpadding="0" cellspacing="0" align="center" width="100%" role="module" data-type="columns" style="padding:0px 0px 0px 0px;" bgcolor="#FFFFFF" data-distribution="1">
<tbody>
  <tr role="module-content">
    <td height="100%" valign="top"><table width="580" style="width:580px; border-spacing:0; border-collapse:collapse; margin:0px 10px 0px 10px;" cellpadding="0" cellspacing="0" align="left" border="0" bgcolor="" class="column column-0">
  <tbody>
    <tr>
      <td style="padding:0px;margin:0px;border-spacing:0;"><table class="wrapper" role="module" data-type="image" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed;" data-muid="dae5891b-ceee-40f7-9315-02ea0b72592e">
<tbody>
  <tr>
    <td style="font-size:6px; line-height:10px; padding:0px 0px 0px 0px;" valign="top" align="center">
      <img class="max-width" border="0" style="display:block; color:#000000; text-decoration:none; font-family:Helvetica, arial, sans-serif; font-size:16px; max-width:20% !important; width:20%; height:auto !important;" width="116" alt="" data-proportionally-constrained="true" data-responsive="true" src="{$domain_url}/assets/img/brand/logo.png">
    </td>
  </tr>
</tbody>
</table></td>
    </tr>
  </tbody>
</table></td>
  </tr>
</tbody>
</table><table border="0" cellpadding="0" cellspacing="0" align="center" width="100%" role="module" data-type="columns" style="padding:10px 0px 10px 0px;" bgcolor="#FFFFFF" data-distribution="1">
<tbody>
  <tr role="module-content">
    <td height="100%" valign="top"><table width="580" style="width:580px; border-spacing:0; border-collapse:collapse; margin:0px 10px 0px 10px;" cellpadding="0" cellspacing="0" align="left" border="0" bgcolor="" class="column column-0">
  <tbody>
    <tr>
      <td style="padding:0px;margin:0px;border-spacing:0;"><table class="module" role="module" data-type="text" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed;" data-muid="c09ad11e-b6f0-426b-bfb5-e854fb1d6b4e">
<tbody>
  <tr>
    <td style="padding:18px 0px 18px 0px; line-height:30px; text-align:inherit;" height="100%" valign="top" bgcolor="" role="module-content"><div><h2 style="text-align: center">{$business_name}</h2>
<div style="font-family: inherit; text-align: center">{$translations["dear"]} {$name}</div>
<div style="font-family: inherit; text-align: center">{$translations["smtpcontactcontent"]}</div>
<div style="font-family: inherit; text-align: center">{$userMessage}</div>
<div></div></div>
</td>
  </tr>
</tbody>
</table><table border="0" cellpadding="0" cellspacing="0" align="center" width="100%" role="module" data-type="columns" style="padding:0px 0px 0px 0px;" bgcolor="#252525" data-distribution="1">
<tbody>
  <tr role="module-content">
    <td height="100%" valign="top"><table width="580" style="width:580px; border-spacing:0; border-collapse:collapse; margin:0px 10px 0px 10px;" cellpadding="0" cellspacing="0" align="left" border="0" bgcolor="" class="column column-0">
  <tbody>
    <tr>
      <td style="padding:0px;margin:0px;border-spacing:0;"><table class="wrapper" role="module" data-type="image" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed;" data-muid="70667641-28f8-4e30-850f-c1783cac6e0b">
<tbody>
  <tr>
    <td style="font-size:6px; line-height:10px; padding:0px 0px 0px 0px;" valign="top" align="center">
      <img class="max-width" border="0" style="display:block; color:#000000; text-decoration:none; font-family:Helvetica, arial, sans-serif; font-size:16px; max-width:10% !important; width:10%; height:auto !important;" width="58" alt="" data-proportionally-constrained="true" data-responsive="true" src="https://gymoneglobal.com/assets/img/text-color-logo.png">
    </td>
  </tr>
</tbody>
</table></td>
    </tr>
  </tbody>
</table></td>
  </tr>
</tbody>
</table><div data-role="module-unsubscribe" class="module" role="module" data-type="unsubscribe" style="background-color:#252525; color:#444444; font-size:12px; line-height:20px; padding:0px 0px 0px 0px; text-align:Center;" data-muid="4e838cf3-9892-4a6d-94d6-170e474d21e5"><div class="Unsubscribe--addressLine"></div><p style="font-size:12px; line-height:20px;"><a class="Unsubscribe--unsubscribeLink" href="https://gymoneglobal.com/" target="_blank" style="">Gymoneglobal.com</a></p></div></td>
                                  </tr>
                                </table>
                                <!--[if mso]>
                              </td>
                            </tr>
                          </table>
                        </center>
                        <![endif]-->
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </div>
  </center>
</body>
</html>
EOD;

  $resultUser = send_mail($env_data, $userEmail, $translations["thankyouforyouremail"], $editedcontent, $business_name, true);

  if ($result && $resultUser) {
    $alerts_html .= '<div class="alert alert-success" role="alert">
                            ' . $translations["successsndedemail"] . '
                        </div>';
    header("Refresh:2");
  } else {
    $alerts_html .= '<div class="alert alert-danger" role="alert">
                            ' . $translations["unexpected-error"] . '
                        </div>';
    header("Refresh:2");
  }
}
?>




<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $business_name; ?> - <?php echo $translations["contactpage"]; ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
    integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <!-- CUSTOM STYLE INSERT HERE! -->
  <link rel="stylesheet" href="../assets/css/default.css">
  <!-- CUSTOM STYLE INSERT HERE! -->
  <link rel="shortcut icon" href="../assets/img/brand/favicon.png" type="image/x-icon">
  <meta name="title" content="<?php echo $business_name; ?> - <?php echo $translations["contactpage"]; ?>">
  <meta name="description" content="<?php echo $description; ?>">
  <meta name="keywords" content="<?php echo $metakey; ?>">
  <meta name="robots" content="index, follow">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <meta name="author" content="<?php echo $business_name; ?>">

<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
.ct-banner{position:relative;min-height:210px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.ct-banner::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.25),rgba(0,0,0,.72))}
.ct-banner::after{content:"";position:absolute;left:0;right:0;bottom:0;height:2px;background:linear-gradient(90deg,transparent,#e11d2a 22%,#e11d2a 78%,transparent)}
.ct-banner-in{position:relative;z-index:2;text-align:center;padding:24px 20px}
.ct-banner-quote{font-family:'Oswald',sans-serif;font-weight:500;text-transform:uppercase;color:#fff;font-size:clamp(1.3rem,3.4vw,2.4rem);margin:0;letter-spacing:.02em;transition:opacity .5s ease,transform .5s ease}
.ct-banner-quote em{font-style:normal;color:#e11d2a}
.ct-banner-quote.ct-out{opacity:0;transform:translateY(-10px)}
.ct-wrap{max-width:1180px;margin:0 auto;padding:64px 20px 72px;font-family:'Inter',system-ui,sans-serif}
.ct-eyebrow{display:block;color:#e11d2a;font-size:.78rem;font-weight:600;letter-spacing:.22em;text-transform:uppercase;margin-bottom:12px}
.ct-title{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:clamp(2.2rem,5.5vw,3.6rem);color:#fff;margin:0;line-height:.98}
.ct-sub{color:#9b9ba1;margin:14px 0 0;max-width:48ch}
.ct-head{padding-bottom:44px;border-bottom:1px solid rgba(255,255,255,.09)}
.ct-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;margin:44px 0 0}
.ct-card{display:block;text-decoration:none;border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:30px 26px;background:rgba(255,255,255,.02);transition:border-color .2s ease,transform .2s ease,background .2s ease}
.ct-card:hover{border-color:#e11d2a;transform:translateY(-4px);background:rgba(225,29,42,.06)}
.ct-card:focus-visible{outline:2px solid #e11d2a;outline-offset:3px}
.ct-ico{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:14px;background:rgba(225,29,42,.14);color:#e11d2a;font-size:1.45rem;margin-bottom:18px}
.ct-ico-wa{background:rgba(37,211,102,.14);color:#25d366}
.ct-card h3{font-family:'Oswald',sans-serif;text-transform:uppercase;color:#fff;font-size:1.3rem;margin:0 0 8px;letter-spacing:.02em}
.ct-card p{color:#b6b6bd;margin:0 0 16px;font-size:.98rem;word-break:break-word}
.ct-go{color:#e11d2a;font-size:.85rem;font-weight:600;display:inline-flex;align-items:center;gap:7px}
.ct-card-wa .ct-go{color:#25d366}
.ct-cta-row{display:flex;flex-direction:column;align-items:center;gap:12px;margin:52px 0 0;text-align:center}
.ct-cta{display:inline-flex;align-items:center;gap:11px;background:#25d366;color:#08130c;text-decoration:none;padding:16px 34px;border-radius:999px;font-weight:700;font-size:1.05rem;transition:transform .18s ease,box-shadow .18s ease}
.ct-cta:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(37,211,102,.3);color:#08130c}
.ct-cta-note{color:#8a8a92;font-size:.85rem}
.ct-form-box{margin:64px auto 0;max-width:620px;border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:38px 32px;background:rgba(255,255,255,.02)}
.ct-form-title{font-family:'Oswald',sans-serif;text-transform:uppercase;color:#fff;font-size:1.6rem;margin:0 0 26px;text-align:center;letter-spacing:.02em}
.ct-field{margin-bottom:18px}
.ct-field label{display:block;color:#9b9ba1;font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;margin-bottom:8px}
.ct-field input,.ct-field textarea{width:100%;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.16);border-radius:12px;padding:13px 15px;color:#fff;font-size:1rem;font-family:inherit}
.ct-field input:focus,.ct-field textarea:focus{outline:none;border-color:#e11d2a;box-shadow:0 0 0 3px rgba(225,29,42,.16)}
.ct-submit{width:100%;background:#e11d2a;color:#fff;border:0;border-radius:999px;padding:15px;font-weight:700;font-size:1rem;cursor:pointer;transition:transform .18s ease}
.ct-submit:hover{transform:translateY(-2px)}
@media(max-width:900px){.ct-grid{grid-template-columns:1fr}.ct-wrap{padding:44px 18px 56px}.ct-banner{min-height:150px}}
@media(prefers-reduced-motion:reduce){.ct-banner-quote,.ct-card,.ct-cta,.ct-submit{transition:none}}
</style>
<style>
.ct-wrap .ct-form-box.ct-form-box{background:rgba(255,255,255,.03)!important;background-color:rgba(255,255,255,.03)!important;border:1px solid rgba(255,255,255,.12)!important;box-shadow:none!important}
.ct-wrap .ct-form-box.ct-form-box *{background-image:none!important}
.ct-wrap .ct-form-box .ct-field input,
.ct-wrap .ct-form-box .ct-field textarea{background:rgba(0,0,0,.55)!important;border:1px solid rgba(255,255,255,.16)!important;color:#fff!important}
.ct-wrap .ct-form-box .ct-field input:focus,
.ct-wrap .ct-form-box .ct-field textarea:focus{border-color:#e11d2a!important;box-shadow:0 0 0 3px rgba(225,29,42,.16)!important}
.ct-wrap .ct-form-box .ct-field label{color:#9b9ba1!important}
.ct-wrap .ct-form-box .ct-form-title{color:#fff!important}
.ct-wrap .ct-form-box .ct-submit{background:#e11d2a!important;color:#fff!important}
</style>
</head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $gkey; ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];

  function gtag() {
    dataLayer.push(arguments);
  }
  gtag('js', new Date());

  gtag('config', '<?php echo $gkey; ?>');
</script>

<body>
  <div class="container">
    <nav class="navbar navbar-expand-lg navbar-light">
      <img class="img" src="../assets/img/brand/logo.png" width="148px" alt="<?php echo $business_name; ?> Logo">
      <button class="navbar-toggler custom-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
        aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav">
          <li class="nav-item">
            <a class="nav-link" href="../"><?php echo $translations["mainpage"]; ?></a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="../trainers/"><?php echo $translations["trainerspage"]; ?></a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="../prices/"><?php echo $translations["pricespage"]; ?></a>
          </li>
          <li class="nav-item">
            <a class="nav-link active" href=""><?php echo $translations["contactpage"]; ?></a>
          </li>
        </ul>
        <ul class="navbar-nav ms-auto">
          <li class="nav-item">
            <a href="../login/" rel="noopener noreferrer" title="Login" class="nav-link ps-0 ps-lg-3 pe-3">
              <i class="bi bi-person-circle"></i>
            </a>
          </li>
        </ul>
      </div>
    </nav>
  </div>
  <div class="container-fluid">
    <div class="row">
      <div class="col bg-imageback ct-banner">
        <div class="ct-banner-in">
          <p class="ct-banner-quote" id="ctRotator">Estamos <em>a un mensaje</em> de distancia</p>
        </div>
      </div>
    </div>
    <section class="ct-wrap">
      <div class="ct-head">
        <span class="ct-eyebrow">Ponte en contacto</span>
        <h1 class="ct-title">Hablemos</h1>
        <p class="ct-sub">Resolvemos tus dudas sobre planes, horarios y entrenamiento personalizado.</p>
      </div>
      <div class="ct-grid">
        <a class="ct-card" href="https://share.google/TW69MDJFtiHFBqjjA" target="_blank" rel="noopener">
          <span class="ct-ico"><i class="bi bi-geo-alt-fill"></i></span>
          <h3>Vis&iacute;tanos</h3>
          <p><?php echo $city; ?>, <?php echo $street; ?> <?php echo $hause_no; ?></p>
          <span class="ct-go">Ver en el mapa <i class="bi bi-arrow-right"></i></span>
        </a>
        <a class="ct-card" href="mailto:<?php echo $mailadress; ?>">
          <span class="ct-ico"><i class="bi bi-envelope-fill"></i></span>
          <h3>Escr&iacute;benos</h3>
          <p><?php echo $mailadress; ?></p>
          <span class="ct-go">Enviar correo <i class="bi bi-arrow-right"></i></span>
        </a>
        <a class="ct-card ct-card-wa" href="https://wa.me/573155425722?text=<?php echo rawurlencode('Hola, quiero informacion sobre las membresias'); ?>" target="_blank" rel="noopener">
          <span class="ct-ico ct-ico-wa"><i class="bi bi-whatsapp"></i></span>
          <h3>WhatsApp</h3>
          <p><?php echo $phoneno; ?></p>
          <span class="ct-go">Abrir chat <i class="bi bi-arrow-right"></i></span>
        </a>
      </div>
      <div class="ct-cta-row">
        <a class="ct-cta" href="https://wa.me/573155425722?text=<?php echo rawurlencode('Hola, quiero informacion sobre las membresias'); ?>" target="_blank" rel="noopener">
          <i class="bi bi-whatsapp"></i> Escr&iacute;benos por WhatsApp
        </a>
        <span class="ct-cta-note">Respondemos en minutos durante el horario de atenci&oacute;n.</span>
      </div>
      <div id="contact" style="position:relative;top:-80px;height:0;overflow:hidden"></div>
      <div class="ct-form-box">
        <h2 class="ct-form-title">O d&eacute;janos tu mensaje</h2>
        <?php echo $alerts_html; ?>
        <form method="post" class="ct-form">
          <div class="ct-field">
            <label for="name"><?php echo $translations["fullname"]; ?></label>
            <input type="text" id="name" name="name" required>
          </div>
          <div class="ct-field">
            <label for="email"><?php echo $translations["email"]; ?></label>
            <input type="email" id="email" name="email" required>
          </div>
          <div class="ct-field">
            <label for="message"><?php echo $translations["message"]; ?></label>
            <textarea id="message" name="message" rows="4" required></textarea>
          </div>
          <button type="submit" class="ct-submit"><?php echo $translations["send"]; ?></button>
        </form>
      </div>
    </section>
  </div>

  <div class="footer">
    <div class="container">
      <div class="row gy-4">
        <div class="mt-3"></div>
        <div class="col-md-4 mb-1">
          <h2 class="mb-4">
            <img src="../assets/img/brand/logo.png" alt="<?php echo $business_name; ?> - Logo" height="105">
          </h2>

          <p><?php echo $city; ?></p>
          <p><?php echo $street; ?> <?php echo $hause_no; ?></p>
        </div>
        <div class="col-md-3 offset-md-1">
          <?php if (!empty($days)): ?>
            <div class="list-group">
              <?php foreach ($days as $day): ?>
                <div class="list-group-itemcustom d-flex justify-content-between align-items-center">
                  <span><strong><?= htmlspecialchars($dayNames[$day['day']]) ?></strong></span>
                  <span class="text-center justify-content-center">
                    <?php if (is_null($day['open_time']) && is_null($day['close_time'])): ?>
                      <span class="badge bg-danger"><?= $translations["closed"]; ?></span>
                    <?php else: ?>
                      <span class="badge bg-success">
                        <?= date('H:i', strtotime($day['open_time'])) ?> -
                        <?= date('H:i', strtotime($day['close_time'])) ?>
                      </span>
                    <?php endif; ?>
                  </span>
                </div>
              <?php endforeach; ?>
              <hr>
              <?php foreach ($exceptions as $ex): ?>
                <?php
                $date = new DateTime($ex['date']);
                $monthName = $months[(int) $date->format('n')];
                $day = $date->format('j');
                ?>
                <div class="list-group-itemcustom d-flex justify-content-between align-items-center">
                  <span><strong><?= $monthName . ' ' . $day . '.' ?></strong></span>

                  <span class="text-center justify-content-center">
                    <?php if ($ex['is_closed']): ?>
                      <span class="badge bg-danger"><?= $translations["closed"]; ?></span>
                    <?php else: ?>
                      <span class="badge bg-warning text-dark">
                        <?= date('H:i', strtotime($ex['open_time'])) ?> -
                        <?= date('H:i', strtotime($ex['close_time'])) ?>
                      </span>
                    <?php endif; ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="col-md-2 offset-md-1">
          <h5 class="text-light mb-4"></h5>

        </div>
      </div>

      <div class="border-top border-secondary pt-3 mt-3">
        <p class="small text-center mb-0">
          Copyright © <?php echo $copyright_year; ?> <?php echo $business_name; ?> -
          <?php echo $translations["copyright"]; ?>
          &nbsp;<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="red" class="bi bi-heart-fill"
            viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M8 1.314C12.438-3.248 23.534 4.735 8 15-7.534 4.736 3.562-3.248 8 1.314">
            </path>
          </svg>
          <a href="https://www.gymoneglobal.com/?lang=<?php echo $lang_code; ?>">GYM One</a>
        </p>
      </div>
    </div>
  </div>
<script>
(function(){
  var el=document.getElementById('ctRotator');
  if(!el) return;
  if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var f=['Estamos <em>a un mensaje</em> de distancia','Resolvemos tus dudas <em>al instante</em>','Ven, conoce <em>la sede</em>','Aqu&iacute; inicia <em>la mejor versi&oacute;n de ti</em>'];
  var i=0;
  setInterval(function(){
    el.classList.add('ct-out');
    setTimeout(function(){ i=(i+1)%f.length; el.innerHTML=f[i]; el.classList.remove('ct-out'); },520);
  },4200);
})();
</script>
</body>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
  integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

</html>