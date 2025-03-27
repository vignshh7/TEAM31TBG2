<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORTAL</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }

        body {
            background: linear-gradient(160deg, #d6e6f2 0%, #a3bffa 50%, #7f9cf5 100%);
            color: #1a2a44;
            padding-top: 30px;
            line-height: 1.8;
            overflow-x: hidden;
            position: relative;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"%3E%3Cpath fill="rgba(255,255,255,0.1)" fill-opacity="1" d="M0,160L48,176C96,192,192,224,288,213.3C384,203,480,149,576,138.7C672,128,768,160,864,181.3C960,203,1056,213,1152,197.3C1248,181,1344,139,1392,117.3L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"%3E%3C/path%3E%3C/svg%3E') no-repeat bottom;
            z-index: -1;
        }

        h1, h2 {
            font-family: 'Arial', sans-serif;
            color: #1a2a44;
            font-weight: 800;
            text-transform: uppercase;
        }

        h1 {
            font-size: 36px;
            text-align: center;
            margin-bottom: 20px;
            letter-spacing: 3px;
            text-shadow: 2px 2px 5px rgba(0, 0, 0, 0.15);
            animation: slideIn 1s ease-out;
        }

        h2 {
            font-size: 30px;
            text-align: center;
            margin-bottom: 15px;
            color: #2d5488;
            position: relative;
            animation: fadeIn 1.2s ease-out;
        }

        h2::after {
            content: '';
            width: 60px;
            height: 4px;
            background: linear-gradient(to right, #63b3ed, #2d5488);
            position: absolute;
            bottom: -12px;
            left: 50%;
            transform: translateX(-50%);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        h2:hover::after {
            width: 80px;
        }

        hr {
            border: 0;
            height: 4px;
            background: linear-gradient(to right, #2d5488, #81c4f8, #2d5488);
            margin: 15px 0;
            border-radius: 4px;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
            animation: stretch 1s ease-out;
        }

        .xx {
            width: 90%;
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px;
            animation: fadeInUp 1s ease-out;
            flex-grow: 1;
            display: flex;
            justify-content: center;
        }

        .inner {
            width: 70%;
            max-width: 600px;
            min-height: 0;
            height: auto;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            border-radius: 25px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.5s ease;
            margin: 0 auto;
        }

        .inner:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.18);
            background: rgba(255, 255, 255, 0.9);
        }

        .image-container {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 20px;
        }

        .image-container a {
            text-align: center;
            text-decoration: none;
            color: #1a2a44;
            padding: 10px;
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(240, 247, 255, 0.8));
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.5s ease;
            position: relative;
            overflow: hidden;
        }

        .image-container a::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(99, 179, 237, 0.2), transparent);
            opacity: 0;
            transition: opacity 0.5s ease;
            z-index: 0;
        }

        .image-container a:hover::before {
            opacity: 1;
        }

        .image-container a:hover {
            transform: translateY(-10px) scale(1.02);
            color: #2d5488;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            background: linear-gradient(135deg, #f0f7ff, #e6f0fa);
        }

        .image-container img {
            width: 180px;
            height: 180px;
            border-radius: 18px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            object-fit: cover;
            transition: transform 0.5s ease, filter 0.4s ease;
            position: relative;
            z-index: 1;
        }

        .image-container a:hover img {
            transform: scale(1.08);
            filter: brightness(1.15) saturate(1.2);
        }

        .image-container p {
            margin-top: 12px;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 1px;
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.05);
            transition: color 0.4s ease;
            position: relative;
            z-index: 1;
        }

        footer {
            background: linear-gradient(160deg, #d6e6f2 0%, #a3bffa 70%, #7f9cf5 100%);
            text-align: center;
            padding: 15px 20px; /* Reduced from 20px 25px */
            margin-top: 20px; /* Reduced from 30px */
            border-top: 3px solid rgba(45, 84, 136, 0.3); /* Reduced from 4px */
            box-shadow: 0 -5px 15px rgba(0, 0, 0, 0.1); /* Reduced shadow */
            position: relative;
            border-radius: 15px 15px 0 0; /* Reduced from 20px */
            overflow: hidden;
            animation: fadeInUp 1.2s ease-out;
        }

        footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, rgba(255, 255, 255, 0.2), transparent 70%);
            opacity: 0.5;
            z-index: 0;
        }

        footer p {
            font-size: 15px; /* Reduced from 17px */
            color: #1a2a44;
            margin: 8px 0; /* Reduced from 10px */
            font-weight: 500;
            position: relative;
            z-index: 1;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.05);
        }

        footer a {
            color: #2d5488;
            text-decoration: none;
            font-size: 16px; /* Reduced from 18px */
            padding: 6px 12px; /* Reduced from 8px 15px */
            transition: all 0.4s ease;
            position: relative;
            font-weight: 600;
            z-index: 1;
            border-radius: 20px; /* Reduced from 25px */
            background: rgba(255, 255, 255, 0.1);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08); /* Reduced shadow */
        }

        footer a:hover {
            color: #ffffff;
            transform: translateY(-4px);
            background: linear-gradient(135deg, #1e40af, #63b3ed);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); /* Reduced shadow */
        }

        footer a::after {
            content: '';
            width: 0;
            height: 2px;
            background: #ffffff;
            position: absolute;
            bottom: 4px;
            left: 50%;
            transform: translateX(-50%);
            transition: width 0.4s ease;
            border-radius: 2px;
        }

        footer a:hover::after {
            width: 50%;
        }

        footer p:first-child {
            font-size: 16px; /* Reduced from 18px */
            font-weight: 600;
            letter-spacing: 0.5px;
            padding-bottom: 4px; /* Reduced from 5px */
            border-bottom: 1px solid rgba(45, 84, 136, 0.2);
        }

        footer p:last-child a {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 16px; /* Reduced from 10px 20px */
            font-size: 15px; /* Reduced from 17px */
            transition: all 0.4s ease;
        }

        footer p:last-child a:hover {
            background: linear-gradient(135deg, #2d5488, #81c4f8);
            color: #ffffff;
            transform: scale(1.05);
        }

        /* Animation Keyframes */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes stretch {
            from { width: 0; }
            to { width: 100%; }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .xx {
                width: 95%;
                padding: 15px;
            }

            .inner {
                width: 85%;
                max-width: 450px;
                padding: 15px;
                min-height: 0;
                height: auto;
            }

            .image-container {
                flex-direction: column;
                gap: 20px;
            }

            .image-container img {
                width: 150px;
                height: 150px;
            }

            h1 {
                font-size: 28px;
            }

            h2 {
                font-size: 26px;
            }

            footer {
                padding: 10px 15px; /* Reduced from 15px */
                border-radius: 10px 10px 0 0; /* Reduced from 15px */
            }

            footer a {
                padding: 5px 10px; /* Reduced from 6px 12px */
                font-size: 14px; /* Reduced from 16px */
            }
        }
    </style>
</head>
<body>
    <h1>DRS Institute of Technology</h1>
    <hr>

    <div class="xx">
        <div class="inner">
            <form>
                <h2>LOGIN</h2>
                <hr>
                <br>

                <div class="image-container">
                    <a href="studentlogin.php">
                        <img src="studentimage.png" alt="Student">
                        <p>Student</p>
                    </a>
                    <a href="facultylogin.php">
                        <img src="adminimage.png" alt="Admin">
                        <p>Faculty</p>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <footer>
        <p>© 2025 DRS Institute Of Technology. All rights reserved.</p>
        <p>
            <a href="https://www.facebook.com/" target="_blank">Facebook</a> | 
            <a href="https://www.instagram.com/" target="_blank">Instagram</a> | 
            <a href="https://x.com/?lang=en&mx=2" target="_blank">Twitter</a> | 
            <a href="https://www.linkedin.com/" target="_blank">LinkedIn</a>
        </p>
        <p>
            <a href="adminlogin.php">Admin Login</a>
        </p>
    </footer>
</body>
</html>