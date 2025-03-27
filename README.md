DRS Institute of Technology - Academic Portal

Overview
--------
The DRS Academic Portal is a web-based system for managing academic tasks at DRS Institute of Technology. It includes dashboards for admins, faculty, and students, built with PHP, MySQL, HTML, CSS, and JavaScript. Features include attendance tracking, marks management, course content uploads, notifications, and real-time chat.

Features
--------
- Dashboards: Admin (admindashboard.php), Faculty (facultydashboard.php), Student (studentdashboard.php).
- Login Systems: Separate logins for admins (adminlogin.php), faculty (facultylogin.php), and students (studentlogin.php).
- Academic Management:
  - Upload course content, mark attendance, and manage student marks (Faculty).
  - View progress and communicate with faculty (Students).
  - Oversee system-wide operations (Admins).
- Real-Time Chat: Faculty can message students and alumni.
- Notifications & Spotlight: Post announcements.
- Responsive UI: Collapsible sidebar with Font Awesome icons.

Prerequisites
-------------
- PHP 7.4+
- MySQL 5.7+
- Apache/Nginx
- Modern browser

Installation
------------
1. Clone the Repository:
   git clone https://github.com/yourusername/drs-academic-portal.git
   cd drs-academic-portal

2. Set Up the Database:
   - Create a MySQL database named college_portal.
   - Import the schema (tables: users, courses, notifications, attendance, lectures, marks, spotlight, quizzes, messages—see full schema in the original code).
   - Populate with initial data.

3. Configure:
   - Place files in your web server’s root (e.g., htdocs in XAMPP).
   - Update database connection in PHP files:
     $conn = new mysqli('localhost', 'root', '', 'college_portal');
   - Create uploads/course_content/ directory and ensure it’s writable.

4. Run:
   - Start your web server and MySQL.
   - Access via http://localhost/drs-academic-portal/index.php.
  
  Project Structure

![image](https://github.com/user-attachments/assets/c0f6997e-fc93-4631-8e91-83a685e33498)

Usage
-----
- Login: Use adminlogin.php, facultylogin.php, or studentlogin.php based on user type.
- Navigate: Access dashboards to manage tasks (e.g., upload content, mark attendance, chat).
- Logout: Via logout.php.

Technologies
------------
- Backend: PHP, MySQL
- Frontend: HTML, CSS, JavaScript, Font Awesome
- Real-Time: Server-Sent Events (SSE)

Contributing
------------
1. Fork the repo.
2. Create a branch (git checkout -b feature/your-feature).
3. Commit changes (git commit -m "Add feature").
4. Push (git push origin feature/your-feature).
5. Open a Pull Request.

