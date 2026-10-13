<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$configFile = dirname(__DIR__) . '/private_html/config.php';
$configAvailable = is_file($configFile);
$storageUnavailable = false;
$errors = [];
$form = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'birth_date' => '',
    'gender' => '',
    'guardian_name' => '',
    'guardian_phone' => '',
    'transport' => '',
];
$allergyOptions = [
    'gluten' => 'Gluten',
    'lactose' => 'Laktose',
    'milk_protein' => 'Melkeprotein',
    'nuts' => 'Nøtter',
    'vegetarian' => 'Vegetar',
];
$allergies = [];
$success = isset($_SESSION['registration_success']) && !isset($_GET['new']);
unset($_SESSION['registration_success']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $field => $value) {
        if (isset($_POST[$field]) && is_string($_POST[$field])) {
            $form[$field] = trim($_POST[$field]);
        }
    }
    if (isset($_POST['allergies'])) {
        if (is_array($_POST['allergies'])) {
            foreach ($_POST['allergies'] as $allergy) {
                if (is_string($allergy) && isset($allergyOptions[$allergy])) {
                    $allergies[] = $allergy;
                } else {
                    $errors['allergies'] = 'Velg gyldige allergier eller kosthensyn.';
                    break;
                }
            }
            $allergies = array_values(array_unique($allergies));
        } else {
            $errors['allergies'] = 'Velg gyldige allergier eller kosthensyn.';
        }
    }

    if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors['form'] = 'Skjemaet kunne ikke bekreftes. Last siden på nytt og prøv igjen.';
    }

    if (!empty($_POST['website'])) {
        $errors['form'] = 'Påmeldingen kunne ikke sendes inn.';
    }

    if ($form['name'] === '') {
        $errors['name'] = 'Skriv inn navnet på deltakeren.';
    } elseif (preg_match('/\s/u', $form['name']) !== 1) {
        $errors['name'] = 'Skriv inn både fornavn og etternavn.';
    } elseif (mb_strlen($form['name']) > 160) {
        $errors['name'] = 'Navnet kan ikke være lengre enn 160 tegn.';
    }

    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 254) {
        $errors['email'] = 'Skriv inn en gyldig e-postadresse.';
    }

    if ($form['phone'] === '') {
        $errors['phone'] = 'Skriv inn mobilnummeret til deltakeren.';
    } elseif (mb_strlen($form['phone']) > 40) {
        $errors['phone'] = 'Telefonnummeret er for langt.';
    }

    if ($form['birth_date'] === '') {
        $errors['birth_date'] = 'Velg fødselsdato.';
    } else {
        $birthDate = DateTime::createFromFormat('!Y-m-d', $form['birth_date']);
        $dateErrors = DateTime::getLastErrors();
        if (!$birthDate || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
            $birthDate->format('Y-m-d') !== $form['birth_date'] || $birthDate > new DateTime('today')) {
            $errors['birth_date'] = 'Velg en gyldig fødselsdato.';
        }
    }

    if (!in_array($form['gender'], ['female', 'male'], true)) {
        $errors['gender'] = 'Velg et av alternativene.';
    }

    if ($form['guardian_name'] === '') {
        $errors['guardian_name'] = 'Skriv inn navnet på en pårørende.';
    } elseif (mb_strlen($form['guardian_name']) > 160) {
        $errors['guardian_name'] = 'Navnet kan ikke være lengre enn 160 tegn.';
    }

    if ($form['guardian_phone'] === '') {
        $errors['guardian_phone'] = 'Skriv inn telefonnummeret til en pårørende.';
    } elseif (mb_strlen($form['guardian_phone']) > 40) {
        $errors['guardian_phone'] = 'Telefonnummeret er for langt.';
    }

    if (!in_array($form['transport'], ['need_ride', 'offer_ride', 'own_arrangement'], true)) {
        $errors['transport'] = 'Velg et av transportalternativene.';
    }

    if (!isset($_POST['consent']) || $_POST['consent'] !== 'yes') {
        $errors['consent'] = 'Du må bekrefte at opplysningene kan brukes til å følge opp påmeldingen.';
    }

    if ($configAvailable && $errors === []) {
        try {
            $database = require $configFile;
            foreach (['host', 'name', 'username', 'password'] as $key) {
                if (!isset($database[$key]) || !is_string($database[$key])) {
                    throw new RuntimeException('Database configuration is missing a required value.');
                }
            }
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $database['host'],
                $database['name']
            );
            $pdo = new PDO($dsn, $database['username'], $database['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $statement = $pdo->prepare(
                'INSERT INTO registrations
                    (participant_name, participant_email, participant_phone, birth_date, gender,
                     guardian_name, guardian_phone, transport_preference, allergies)
                 VALUES
                    (:name, :email, :phone, :birth_date, :gender, :guardian_name, :guardian_phone, :transport, :allergies)'
            );
            $statement->execute([
                ':name' => $form['name'],
                ':email' => $form['email'],
                ':phone' => $form['phone'] !== '' ? $form['phone'] : null,
                ':birth_date' => $form['birth_date'] !== '' ? $form['birth_date'] : null,
                ':gender' => $form['gender'] !== '' ? $form['gender'] : null,
                ':guardian_name' => $form['guardian_name'] !== '' ? $form['guardian_name'] : null,
                ':guardian_phone' => $form['guardian_phone'] !== '' ? $form['guardian_phone'] : null,
                ':transport' => $form['transport'] !== '' ? $form['transport'] : null,
                ':allergies' => $allergies !== [] ? json_encode($allergies, JSON_THROW_ON_ERROR) : null,
            ]);

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            if ($isAjaxRequest) {
                respondJson([
                    'success' => true,
                    'message' => 'Du er påmeldt! Takk for at du blir med. Vi gleder oss til å se deg.',
                    'csrfToken' => $_SESSION['csrf_token'],
                ], 201);
            }
            $_SESSION['registration_success'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#pamelding', true, 303);
            exit;
        } catch (Throwable $exception) {
            error_log('Get Focused registration could not be saved: ' . $exception->getMessage());
            $errors['form'] = 'Påmeldingen kunne ikke lagres akkurat nå. Prøv igjen litt senere.';
            $storageUnavailable = true;
        }
    } elseif (!$configAvailable && $errors === []) {
        $errors['form'] = 'Påmeldingen er ikke tilgjengelig akkurat nå. Prøv igjen litt senere.';
        $storageUnavailable = true;
    }

    if ($isAjaxRequest) {
        respondJson([
            'success' => false,
            'message' => $errors['form'] ?? null,
            'errors' => $errors,
            'csrfToken' => $_SESSION['csrf_token'],
        ], $storageUnavailable ? 503 : 422);
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fieldError(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<span class="field-error">' . escape($errors[$field]) . '</span>' : '';
}

function respondJson(array $payload, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Meld deg på Get Focused gjennom Pinsekirken Mjøndalen.">
    <meta name="theme-color" content="#211714">
    <title>Påmelding – Get Focused | Pinsekirken Mjøndalen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=20261013-7">
    <script src="registration.js" defer></script>
</head>
<body>
    <header class="site-header">
        <a class="wordmark" href="#top" aria-label="Get Focused – til toppen">
            <span class="wordmark-mark" aria-hidden="true">GF</span>
            <span>GET FOCUSED<span class="wordmark-sub">PINSEKIRKEN MJØNDALEN</span></span>
        </a>
        <nav aria-label="Hovedmeny">
            <a class="nav-external" href="https://www.getfocused.no/" target="_blank" rel="noopener noreferrer">Get Focused <span aria-hidden="true">↗</span></a>
            <a class="nav-external" href="https://pkmj.no/youth-night" target="_blank" rel="noopener noreferrer">Youth Night <span aria-hidden="true">↗</span></a>
            <a class="nav-cta" href="#pamelding">Meld deg på <span aria-hidden="true">↓</span></a>
        </nav>
    </header>

    <main id="top">
        <section class="hero">
            <div class="hero-copy">
                <p class="eyebrow"><span class="eyebrow-dot"></span> PINSEKIRKEN MJØNDALEN</p>
                <h1>Tro. Fellesskap.<br><span>Get Focused.</span></h1>
                <p class="hero-lead">Bli med oss på Get Focused – en samling for ungdommer med rom for tilbedelse, nye vennskap og tro som får vokse.</p>
                <a class="button button-orange" href="#pamelding">Jeg vil være med <span aria-hidden="true">↘</span></a>
                <a class="text-link" href="https://www.getfocused.no/" target="_blank" rel="noopener noreferrer">Les mer om Get Focused <span aria-hidden="true">↗</span></a>
            </div>
            <div class="hero-photo">
                <img src="_images/vince-fleming-4EeVLcCJfiM-unsplash.jpg" alt="Ungdommer samlet i lovsang under Get Focused">
                <span class="photo-label">ET FELLESSKAP Å VOKSE I</span>
            </div>
            <div class="hero-bottom">
                <span>EN HELG MED MENING</span>
                <span>FELLESSKAP&nbsp; · &nbsp;TILBEDELSE&nbsp; · &nbsp;TRO</span>
            </div>
        </section>

        <section class="intro" aria-label="Om Get Focused">
            <p class="eyebrow eyebrow-dark">MER ENN ET ARRANGEMENT</p>
            <p class="intro-quote">Vi vil se en bevegelse av ungdommer <span>forent i kjærlighet</span>, merket av tilbedelse og kjent for rettferdighet.</p>
            <p class="intro-note">Har du spørsmål eller tanker? Det er rom for å komme akkurat som du er. Les mer om ungdomsmiljøet vårt hos <a href="https://pkmj.no/youth-night" target="_blank" rel="noopener noreferrer">Youth Night <span aria-hidden="true">↗</span></a>.</p>
        </section>

        <section class="pricing" aria-labelledby="pricing-title">
            <div class="pricing-heading">
                <div>
                    <p class="eyebrow eyebrow-dark">KOSTNADSFORDELING</p>
                    <h2 id="pricing-title">Pris og støtte<span>.</span></h2>
                </div>
                <p>Menigheten bidrar ut fra hvilken del av menigheten du hører til. Støtten gjelder kun for deg som går fast eller semi-fast på møtene våre.</p>
            </div>
            <div class="pricing-cards">
                <article class="pricing-card pricing-youth">
                    <p class="pricing-type">YOUTH NIGHT</p>
                    <h3>Ungdom</h3>
                    <div class="price-detail">
                        <span class="price-icon" aria-hidden="true">+</span>
                        <p><strong>Menigheten dekker</strong><br>Overnatting og matpass</p>
                    </div>
                    <div class="price-detail">
                        <span class="price-icon price-icon-outline" aria-hidden="true">↗</span>
                        <p><strong>Du betaler selv</strong><br>Festivalpass: 750,-</p>
                    </div>
                </article>
                <article class="pricing-card pricing-young-adult">
                    <p class="pricing-type">UNG VOKSEN</p>
                    <h3>Ung voksen</h3>
                    <div class="price-detail">
                        <span class="price-icon price-icon-outline" aria-hidden="true">↗</span>
                        <p><strong>Du betaler selv</strong><br>Festivalpass: 750,-</p>
                    </div>
                    <div class="price-detail">
                        <span class="price-icon" aria-hidden="true">+</span>
                        <p><strong>Menigheten dekker halvparten av</strong><br>Matpass: 599,-<br>Frokost: 210,-<br>Overnatting: 600–800,-</p>
                    </div>
                    <div class="price-detail">
                        <span class="price-icon" aria-hidden="true">≈</span>
                        <p><strong>Omtrentlig totalpris for deg</strong><br>Ca. 1 500 kr</p>
                    </div>
                </article>
            </div>
            <p class="pricing-footnote">Overnattingsprisen avhenger av hvor mange som deler rom.</p>
        </section>

        <section class="registration" id="pamelding">
            <div class="registration-heading">
                <div>
                    <p class="eyebrow eyebrow-dark">BLI MED OSS</p>
                    <h2>Påmelding<span>.</span></h2>
                </div>
                <p class="required-note" <?= $success ? 'hidden' : '' ?>><span aria-hidden="true">*</span> Må fylles ut</p>
            </div>

            <?php if ($success): ?>
                <div class="thank-you-card" id="registration-thanks" role="status" aria-live="polite">
                    <span class="thank-you-icon" aria-hidden="true">✓</span>
                    <p class="thank-you-eyebrow">PÅMELDINGEN ER REGISTRERT</p>
                    <h3>Takk for påmeldingen!</h3>
                    <p>Vi gleder oss til å dele Get Focused med deg. Du hører fra oss snart med mer informasjon.</p>
                    <a class="button button-dark" href="?new=1#pamelding" data-register-another>Meld på en deltaker til <span aria-hidden="true">↗</span></a>
                </div>
            <?php endif; ?>
            <?php if (isset($errors['form'])): ?>
                <div class="notice notice-error" role="alert"><?= escape($errors['form']) ?></div>
            <?php endif; ?>
            <?php if (!$configAvailable): ?>
                <div class="notice notice-error" role="status">Påmeldingsskjemaet er ikke satt opp ennå. Ta kontakt med Pinsekirken Mjøndalen.</div>
            <?php endif; ?>

            <form id="registration-form" method="post" action="#pamelding" <?= $success ? 'hidden' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <div class="website-field" aria-hidden="true">
                    <label for="website">La dette feltet stå tomt</label>
                    <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
                </div>

                <div class="form-grid">
                    <div class="field field-wide">
                        <label for="name">Fullt navn på deltaker (fornavn og etternavn) <span>*</span></label>
                        <input id="name" name="name" type="text" autocomplete="name" maxlength="160" pattern=".*\s+.*" title="Oppgi både fornavn og etternavn." required value="<?= escape($form['name']) ?>" aria-describedby="<?= isset($errors['name']) ? 'name-error' : '' ?>">
                        <?= isset($errors['name']) ? '<span class="field-error" id="name-error">' . escape($errors['name']) . '</span>' : '' ?>
                    </div>

                    <div class="field">
                        <label for="email">E-post til deltaker <span>*</span></label>
                        <input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= escape($form['email']) ?>" aria-describedby="<?= isset($errors['email']) ? 'email-error' : '' ?>">
                        <?= isset($errors['email']) ? '<span class="field-error" id="email-error">' . escape($errors['email']) . '</span>' : '' ?>
                    </div>

                    <div class="field">
                        <label for="phone">Mobiltelefon <span>*</span></label>
                        <input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="40" placeholder="+47" required value="<?= escape($form['phone']) ?>" aria-describedby="<?= isset($errors['phone']) ? 'phone-error' : '' ?>">
                        <?= isset($errors['phone']) ? '<span class="field-error" id="phone-error">' . escape($errors['phone']) . '</span>' : '' ?>
                    </div>

                    <div class="field">
                        <label for="birth_date">Fødselsdato <span>*</span></label>
                        <input id="birth_date" name="birth_date" type="date" required value="<?= escape($form['birth_date']) ?>" aria-describedby="<?= isset($errors['birth_date']) ? 'birth-date-error' : '' ?>">
                        <?= isset($errors['birth_date']) ? '<span class="field-error" id="birth-date-error">' . escape($errors['birth_date']) . '</span>' : '' ?>
                    </div>

                    <fieldset class="field field-wide">
                        <legend>Kjønn <span>*</span></legend>
                        <div class="choice-row">
                            <label class="choice"><input type="radio" name="gender" value="female" required <?= $form['gender'] === 'female' ? 'checked' : '' ?>> <span>Kvinne</span></label>
                            <label class="choice"><input type="radio" name="gender" value="male" <?= $form['gender'] === 'male' ? 'checked' : '' ?>> <span>Mann</span></label>
                        </div>
                        <?= fieldError($errors, 'gender') ?>
                    </fieldset>

                    <fieldset class="field field-wide allergy-field">
                        <legend>Allergier <span class="optional">· valgfritt</span></legend>
                        <div class="allergy-options">
                            <label class="choice allergy-select-all">
                                <input id="select-allergies" type="checkbox">
                                <span>Velg alle</span>
                            </label>
                            <?php foreach ($allergyOptions as $value => $label): ?>
                                <label class="choice">
                                    <input type="checkbox" name="allergies[]" value="<?= escape($value) ?>" <?= in_array($value, $allergies, true) ? 'checked' : '' ?> aria-describedby="<?= isset($errors['allergies']) ? 'allergies-error' : '' ?>">
                                    <span><?= escape($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?= isset($errors['allergies']) ? '<span class="field-error" id="allergies-error">' . escape($errors['allergies']) . '</span>' : '' ?>
                    </fieldset>

                    <div class="field field-wide field-divider">
                        <p class="section-label">PÅRØRENDE/FORELDRE</p>
                    </div>
                    <div class="field">
                        <label for="guardian_name">Navn på pårørende/foreldre <span>*</span></label>
                        <input id="guardian_name" name="guardian_name" type="text" autocomplete="name" maxlength="160" required value="<?= escape($form['guardian_name']) ?>" aria-describedby="<?= isset($errors['guardian_name']) ? 'guardian-name-error' : '' ?>">
                        <?= isset($errors['guardian_name']) ? '<span class="field-error" id="guardian-name-error">' . escape($errors['guardian_name']) . '</span>' : '' ?>
                    </div>
                    <div class="field">
                        <label for="guardian_phone">Telefon til pårørende/foreldre <span>*</span></label>
                        <input id="guardian_phone" name="guardian_phone" type="tel" autocomplete="tel" maxlength="40" placeholder="+47" required value="<?= escape($form['guardian_phone']) ?>" aria-describedby="<?= isset($errors['guardian_phone']) ? 'guardian-phone-error' : '' ?>">
                        <?= isset($errors['guardian_phone']) ? '<span class="field-error" id="guardian-phone-error">' . escape($errors['guardian_phone']) . '</span>' : '' ?>
                    </div>

                    <fieldset class="field field-wide">
                        <legend>Hvordan ordner du transport? <span>*</span></legend>
                        <div class="transport-options">
                            <label class="transport-choice">
                                <input type="radio" name="transport" value="need_ride" required <?= $form['transport'] === 'need_ride' ? 'checked' : '' ?>>
                                <span class="transport-icon" aria-hidden="true">↗</span>
                                <span><strong>Jeg trenger skyss</strong><small>Vi tar kontakt om muligheter.</small></span>
                            </label>
                            <label class="transport-choice">
                                <input type="radio" name="transport" value="offer_ride" <?= $form['transport'] === 'offer_ride' ? 'checked' : '' ?>>
                                <span class="transport-icon" aria-hidden="true">＋</span>
                                <span><strong>Jeg kan tilby skyss</strong><small>Vi følger opp om det trengs.</small></span>
                            </label>
                            <label class="transport-choice">
                                <input type="radio" name="transport" value="own_arrangement" <?= $form['transport'] === 'own_arrangement' ? 'checked' : '' ?>>
                                <span class="transport-icon" aria-hidden="true">→</span>
                                <span><strong>Jeg ordner selv</strong><small>Transport er i orden.</small></span>
                            </label>
                        </div>
                        <?= fieldError($errors, 'transport') ?>
                    </fieldset>

                    <div class="field field-wide consent-field">
                        <label class="choice consent-choice">
                            <input type="checkbox" name="consent" value="yes" <?= isset($_POST['consent']) && $_POST['consent'] === 'yes' ? 'checked' : '' ?> required>
                            <span>Jeg samtykker til at Pinsekirken Mjøndalen bruker opplysningene for å håndtere og følge opp denne påmeldingen. <span>*</span></span>
                        </label>
                        <?php if (isset($errors['consent'])): ?><span class="field-error"><?= escape($errors['consent']) ?></span><?php endif; ?>
                    </div>
                </div>

                <div class="form-footer">
                    <p>Opplysningene brukes kun for å følge opp påmeldingen.</p>
                    <button class="button button-dark" type="submit" <?= !$configAvailable ? 'disabled' : '' ?>>Send påmelding <span aria-hidden="true">↗</span></button>
                </div>
            </form>
        </section>

        <section class="moments" aria-labelledby="moments-title">
            <div class="moments-heading">
                <div>
                    <p class="eyebrow eyebrow-dark">SAMMEN I TROEN</p>
                    <h2 id="moments-title">Et sted å høre til<span>.</span></h2>
                </div>
                <p>Lovsang, vennskap og gode øyeblikk – det er bedre når vi opplever det sammen.</p>
            </div>
            <div class="photo-grid">
                <figure class="photo-card photo-card-tall">
                    <img src="_images/kyle-smith-VsGWBczIg20-unsplash.jpg" alt="Ungdom løfter hendene under lovsang" loading="lazy">
                    <figcaption>PLASS TIL Å VÆRE DEG</figcaption>
                </figure>
                <figure class="photo-card">
                    <img src="_images/martin-marek-hWicgblYEQ8-unsplash.jpg" alt="Ungdommer synger sammen" loading="lazy">
                    <figcaption>VI ER BEDRE SAMMEN</figcaption>
                </figure>
                <figure class="photo-card">
                    <img src="_images/nathan-mullet-JCqx6uOZrj0-unsplash.jpg" alt="Ungdommer samlet med hendene løftet" loading="lazy">
                    <figcaption>FELLESSKAP MED MENING</figcaption>
                </figure>
                <figure class="photo-card photo-card-tall">
                    <img src="_images/vince-fleming-afrNpD1wKZU-unsplash.jpg" alt="Ungdom opplever lovsang sammen" loading="lazy">
                    <figcaption>ROM FOR TRO OG GLEDE</figcaption>
                </figure>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <a class="wordmark wordmark-footer" href="#top">
            <span class="wordmark-mark" aria-hidden="true">GF</span>
            <span>GET FOCUSED<span class="wordmark-sub">PINSEKIRKEN MJØNDALEN</span></span>
        </a>
        <p>Alle er velkommen, akkurat som de er.</p>
        <div class="footer-links">
            <a href="https://www.getfocused.no/" target="_blank" rel="noopener noreferrer">getfocused.no ↗</a>
            <a href="https://pkmj.no/youth-night" target="_blank" rel="noopener noreferrer">Youth Night ↗</a>
            <a class="footer-credit" href="https://onto.no" target="_blank" rel="noopener noreferrer">
                <span>Design og utvikling av</span>
                <img src="/_images/onto_logo_white.svg" alt="ONTO AS" width="78" height="20">
            </a>
        </div>
    </footer>
</body>
</html>
