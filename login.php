<!DOCTYPE html>
<html lang="en" xml:lang="en" dir="ltr" class="yui3-js-enabled">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLE System – Login</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom Styles -->
    <link href="assets/css/style.css" rel="stylesheet">

    <style>
        /* latin-ext */
        @font-face {
          font-family: 'Just Another Hand';
          font-style: normal;
          font-weight: 400;
          font-display: swap;
          src: url(https://fonts.gstatic.com/s/justanotherhand/v21/845CNN4-AJyIGvIou-6yJKyptyOpOfr2DGiVSw.woff2) format('woff2');
          unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        /* latin */
        @font-face {
          font-family: 'Just Another Hand';
          font-style: normal;
          font-weight: 400;
          font-display: swap;
          src: url(https://fonts.gstatic.com/s/justanotherhand/v21/845CNN4-AJyIGvIou-6yJKyptyOpOfr4DGg.woff2) format('woff2');
          unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }

        body#page-login-index {
            background: url("includes/VLE background.jpg") no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
        }
    </style>
</head>

<body id="page-login-index"
      class="format-site path-login gecko dir-ltr lang-en yui-skin-sam course-1 context-1 notloggedin theme-moove-login jsenabled">

    <!-- Toast Wrapper -->
    <div class="toast-wrapper mx-auto py-3 fixed-top"
         role="status"
         aria-live="polite"
         aria-atomic="true"></div>

    <div id="page-wrapper" class="container">

        <div class="row justify-content-start align-items-center min-vh-100">
            <div class="col-md-6 col-lg-5">

                <div class="card shadow-lg border-0" style="background-color: rgba(255, 255, 255, 0.8);">
                    <div class="card-header text-center" style="background-color: rgba(0, 123, 255, 0.8); color: white;">
                        <h4 class="mb-1">Virtual Learning Environment</h4>
                        <p class="mb-0 small">Login to access your courses</p>
                    </div>

                    <div class="card-body p-4">

                        <?php
                        // Define LOGIN_PAGE constant to prevent redirect loops
                        define('LOGIN_PAGE', true);
                        session_start();
                        
                        // Check for timeout parameter
                        if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
                            echo '<div class="alert alert-warning text-center">' .
                                 '<i class="fas fa-clock"></i> ' .
                                 'Your session has expired due to inactivity. Please login again.' .
                                 '</div>';
                        }
                        
                        if (isset($_SESSION['login_error'])) {
                            echo '<div class="alert alert-danger text-center">' .
                                 htmlspecialchars($_SESSION['login_error']) .
                                 '</div>';
                            unset($_SESSION['login_error']);
                        }
                        ?>

                        <form method="POST" action="login_process.php">
                            <div class="mb-3">
                                <label for="username_email" class="form-label">Username or Email</label>
                                <input type="text" class="form-control" id="username_email" name="username_email" required>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>

                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">Login</button>
                            </div>
                        </form>

                        <div class="text-center mt-3">
                            <a href="forgot_password.php" class="text-decoration-none">
                                Forgot Password?
                            </a>
                        </div>

                    </div>
                </div>

            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <div id="yui3-css-stamp" class="" style="position: absolute !important; visibility: hidden !important"></div>
</body>
</html>
