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

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$currency = $env_data['CURRENCY'] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../assets/lang/";

$langFile = $langDir . "$lang.json";

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

$sql = "SELECT * FROM trainers";
$result = $conn->query($sql);

?>




<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $business_name; ?> - <?php echo $translations["trainerspage"]; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- CUSTOM STYLE INSERT HERE! -->
    <link rel="stylesheet" href="../assets/css/default.css">
    <!-- CUSTOM STYLE INSERT HERE! -->
    <link rel="shortcut icon" href="../assets/img/brand/favicon.png" type="image/x-icon">
    <meta name="title" content="<?php echo $business_name; ?> - <?php echo $translations["trainerspage"]; ?>">
    <meta name="description" content="<?php echo $description; ?>">
    <meta name="keywords" content="<?php echo $metakey; ?>">
    <meta name="robots" content="index, follow">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="author" content="<?php echo $business_name; ?>">

<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
.tr-wrap{max-width:1180px;margin:0 auto;padding:72px 20px 40px;font-family:'Inter',system-ui,sans-serif}
.tr-wrap .tr-eyebrow{display:block;color:#e11d2a;font-size:.78rem;font-weight:600;letter-spacing:.22em;text-transform:uppercase;margin-bottom:14px}
.tr-wrap .tr-title{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:clamp(2.4rem,6vw,4rem);line-height:.95;color:#fff;margin:0}
.tr-wrap .tr-sub{color:#9b9ba1;font-size:1.05rem;margin:16px 0 0;max-width:46ch}
.tr-wrap .tr-head{padding-bottom:56px;border-bottom:1px solid rgba(255,255,255,.09)}
.tr-wrap .tr-card{display:grid;grid-template-columns:minmax(0,400px) minmax(0,1fr);gap:56px;align-items:center;padding:72px 0;border-bottom:1px solid rgba(255,255,255,.09)}
.tr-wrap .tr-media{position:relative}
.tr-wrap .tr-media::after{content:"";position:absolute;inset:0;border:2px solid #e11d2a;border-radius:22px;transform:translate(16px,16px);z-index:0}
.tr-wrap .tr-alt .tr-media::after{transform:translate(-16px,16px)}
.tr-wrap .tr-media img{position:relative;z-index:1;width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:22px;display:block;background:#111}
.tr-wrap .tr-name{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:clamp(1.9rem,4vw,3rem);line-height:1;color:#fff;margin:0;letter-spacing:.01em}
.tr-wrap .tr-rule{width:62px;height:3px;background:#e11d2a;margin:20px 0 24px}
.tr-wrap .tr-desc{color:#b6b6bd;font-size:1rem;line-height:1.8;max-width:60ch;text-align:left}
.tr-wrap .tr-desc p{margin:0 0 14px;text-align:left}
.tr-wrap .tr-prices{display:flex;gap:14px;flex-wrap:wrap;margin:28px 0 26px}
.tr-wrap .tr-price{border:1px solid rgba(255,255,255,.14);border-radius:14px;padding:12px 20px;background:rgba(255,255,255,.02)}
.tr-wrap .tr-price span{display:block;color:#8a8a92;font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;margin-bottom:4px}
.tr-wrap .tr-price strong{font-family:'Oswald',sans-serif;font-size:1.45rem;color:#fff;font-weight:500}
.tr-wrap .tr-cta{display:inline-flex;align-items:center;gap:10px;background:#e11d2a;color:#fff;text-decoration:none;padding:14px 28px;border-radius:999px;font-weight:600;font-size:.98rem;transition:transform .18s ease,box-shadow .18s ease}
.tr-wrap .tr-cta:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(225,29,42,.32);color:#fff}
.tr-wrap .tr-cta:focus-visible{outline:2px solid #fff;outline-offset:3px}
@media(max-width:900px){
 .tr-wrap{padding:48px 18px 24px}
 .tr-wrap .tr-card{grid-template-columns:1fr;gap:32px;padding:48px 0}
 .tr-wrap .tr-media{max-width:380px}
 .tr-wrap .tr-media::after,.tr-wrap .tr-alt .tr-media::after{transform:translate(10px,10px)}
}
@media(prefers-reduced-motion:reduce){.tr-wrap .tr-cta{transition:none}}
</style>
<style>
.tr-banner{position:relative;min-height:230px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.tr-banner::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.25) 0%,rgba(0,0,0,.72) 100%)}
.tr-banner::after{content:"";position:absolute;left:0;right:0;bottom:0;height:2px;background:linear-gradient(90deg,transparent,#e11d2a 22%,#e11d2a 78%,transparent)}
.tr-banner-in{position:relative;z-index:2;text-align:center;padding:26px 20px}
.tr-banner-tag{display:inline-block;color:#e11d2a;font-family:'Inter',system-ui,sans-serif;font-size:.7rem;font-weight:600;letter-spacing:.3em;text-transform:uppercase;margin-bottom:14px}
.tr-banner-quote{font-family:'Oswald',sans-serif;font-weight:500;text-transform:uppercase;color:#fff;font-size:clamp(1.3rem,3.4vw,2.4rem);line-height:1.15;margin:0;letter-spacing:.02em}
.tr-banner-quote em{font-style:normal;color:#e11d2a}
@media(max-width:900px){.tr-banner{min-height:160px}}
</style>
<style>
.tr-banner-quote{transition:opacity .5s ease,transform .5s ease}
.tr-banner-quote.tr-rotator-out{opacity:0;transform:translateY(-10px)}
@media(prefers-reduced-motion:reduce){.tr-banner-quote{transition:none}}
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
            <button class="navbar-toggler custom-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false"
                aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="../"><?php echo $translations["mainpage"]; ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="#"><?php echo $translations["trainerspage"]; ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../prices/"><?php echo $translations["pricespage"]; ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../contact/"><?php echo $translations["contactpage"]; ?></a>
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
            <div class="col bg-imageback tr-banner">
                <div class="tr-banner-in">
                    <p class="tr-banner-quote" id="trRotator" data-i="0">La disciplina transforma <em>m&aacute;s que la motivaci&oacute;n</em></p>
                </div>
            </div>
        </div>
        <section class="tr-wrap">
            <div class="tr-head">
                <span class="tr-eyebrow">Entrenamiento personalizado</span>
                <h1 class="tr-title">Nuestros entrenadores</h1>
                <p class="tr-sub">Sesiones uno a uno, plan armado para tu objetivo y seguimiento en cada entreno.</p>
            </div>
            <?php if ($result->num_rows > 0): $tIdx = 0; ?>
                <?php while ($row = $result->fetch_assoc()): $tIdx++; ?>
                    <article class="tr-card <?php echo ($tIdx % 2 === 0) ? 'tr-alt' : ''; ?>">
                        <div class="tr-media">
                            <img src="<?php echo '../assets/img/trainers/trainer_' . (int)$row['id'] . '.png'; ?>"
                                 alt="<?php echo htmlspecialchars($row['name']); ?>" loading="lazy">
                        </div>
                        <div class="tr-body">
                            <h2 class="tr-name"><?php echo htmlspecialchars($row['name']); ?></h2>
                            <div class="tr-rule"></div>
                            <div class="tr-desc"><?php echo $row['description']; ?></div>
                            <div class="tr-prices">
                                <div class="tr-price"><span>1 sesion</span><strong>$<?php echo number_format((float)$row['price_1hour'], 0, ',', '.'); ?></strong></div>
                                <div class="tr-price"><span>10 sesiones</span><strong>$<?php echo number_format((float)$row['price_10sessions'], 0, ',', '.'); ?></strong></div>
                            </div>
                            <a class="tr-cta" target="_blank" rel="noopener"
                               href="https://wa.me/573155425722?text=<?php echo rawurlencode('Hola, quiero agendar una sesion con ' . $row['name']); ?>">
                                <i class="bi bi-whatsapp"></i> Agendar por WhatsApp
                            </a>
                        </div>
                    </article>
                <?php endwhile; ?>
            <?php endif; ?>
        </section>


        <div class="footer">
            <div class="container">
                <div class="row gy-4">
                    <div class="mt-3"></div>
                    <div class="col-md-4 mb-1">
                        <h2 class="mb-4">
                            <img src="../assets/img/brand/logo.png" alt="<?php echo $business_name; ?> - Logo"
                                height="105">
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
                        &nbsp;<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="red"
                            class="bi bi-heart-fill" viewBox="0 0 16 16">
                            <path fill-rule="evenodd"
                                d="M8 1.314C12.438-3.248 23.534 4.735 8 15-7.534 4.736 3.562-3.248 8 1.314">
                            </path>
                        </svg>
                        <a href="https://www.gymoneglobal.com/?lang=<?php echo $lang_code; ?>">GYM One</a>
                    </p>
                </div>
            </div>
        </div>
<script>
(function(){
  var el=document.getElementById('trRotator');
  if(!el) return;
  if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var frases=[
    'La disciplina transforma <em>m&aacute;s que la motivaci&oacute;n</em>',
    'Cada sesi&oacute;n con <em>plan y prop&oacute;sito</em>',
    'Tu objetivo, <em>nuestro entrenamiento</em>',
    'Aqu&iacute; inicia <em>la mejor versi&oacute;n de ti</em>'
  ];
  var i=0;
  setInterval(function(){
    el.classList.add('tr-rotator-out');
    setTimeout(function(){
      i=(i+1)%frases.length;
      el.innerHTML=frases[i];
      el.classList.remove('tr-rotator-out');
    },520);
  },4200);
})();
</script>
</body>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"></script>

</html>