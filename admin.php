<?php
// Admin Dashboard for MicroBlog
session_start();

// Configuration
define('DEFAULT_PASSWORD_HASH', password_hash('admin123', PASSWORD_DEFAULT)); // Hardcoded hashed password
define('POSTS_JSON', 'posts.json');
define('POSTS_DIR', 'posts/');
define('IMAGES_DIR', 'images/');
define('CACHE_DIR', 'cache/');
define('SETTINGS_FILE', 'site_settings.json');
define('POSTS_PER_PAGE', 25);

// Initialize CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Generate CSRF token
function generateCsrfToken() {
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Comprehensive Input Validation
function validateAdminInput($input, $type = 'string') {
    if ($input === null) return '';
    
    $input = trim($input);
    
    switch ($type) {
        case 'title':
            // Allow only alphanumeric, spaces, and basic punctuation
            $filtered = preg_replace('/[^\w\s\-\.\,\!\?]/u', '', $input);
            return htmlspecialchars(substr($filtered, 0, 200), ENT_QUOTES, 'UTF-8');
            
        case 'slug':
            $slug = strtolower($input);
            $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
            $slug = preg_replace('/-+/', '-', $slug);
            return trim($slug, '-');
            
        case 'tags':
            $tags = array_map('trim', explode(',', $input));
            $tags = array_slice($tags, 0, 10);
            $validTags = [];
            foreach ($tags as $tag) {
                $cleanTag = preg_replace('/[^\w\s\-]/u', '', $tag);
                if (!empty($cleanTag)) {
                    $validTags[] = htmlspecialchars($cleanTag, ENT_QUOTES, 'UTF-8');
                }
            }
            return $validTags;
            
        case 'url':
            $url = filter_var($input, FILTER_SANITIZE_URL);
            return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
            
        case 'email':
            $email = filter_var($input, FILTER_SANITIZE_EMAIL);
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
            
        case 'int':
            return filter_var($input, FILTER_VALIDATE_INT) ? intval($input) : 0;
            
        case 'float':
            return filter_var($input, FILTER_VALIDATE_FLOAT) ? floatval($input) : 0.0;
            
        case 'html':
            // Allow safe HTML but strip dangerous tags
            $allowed_tags = '<h1><h2><h3><h4><h5><h6><p><br><strong><b><em><i><u><ul><ol><li><a><img><div><span><code><pre><blockquote>';
            $cleaned = strip_tags($input, $allowed_tags);
            return $cleaned;
            
        case 'text':
        default:
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }
}

// Generate unique slug
function generateUniqueSlug($title, $existing_slug = null) {
    $slug = validateAdminInput($title, 'slug');
    $original_slug = $slug;
    
    // Load existing posts to check for duplicates
    $posts = [];
    if (file_exists(POSTS_JSON)) {
        $postsData = json_decode(file_get_contents(POSTS_JSON), true);
        $posts = $postsData['posts'] ?? [];
    }
    
    $counter = 1;
    while (true) {
        $slug_exists = false;
        foreach ($posts as $post) {
            if ($post['slug'] === $slug && $slug !== $existing_slug) {
                $slug_exists = true;
                break;
            }
        }
        
        if (!$slug_exists) {
            break;
        }
        
        $slug = $original_slug . '-' . $counter;
        $counter++;
    }
    
    return $slug;
}

// Clear cache function
function clearCache() {
    if (file_exists(CACHE_DIR)) {
        $files = glob(CACHE_DIR . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

// Handle logout
function adminLogout() {
    // Clear cache
    clearCache();
    
    // Set files and directories to read-only
    $files = [POSTS_JSON, 'index.php', 'admin.php', 'robots.txt', 'sitemap.xml', SETTINGS_FILE];
    $directories = [POSTS_DIR, IMAGES_DIR, CACHE_DIR];
    
    foreach ($files as $file) {
        if (file_exists($file)) {
            chmod($file, 0444);
        }
    }
    
    foreach ($directories as $dir) {
        if (file_exists($dir)) {
            chmod($dir, 0555);
            // Make files in directories read-only too
            $files_in_dir = glob($dir . '*');
            foreach ($files_in_dir as $file) {
                if (is_file($file)) {
                    chmod($file, 0444);
                }
            }
        }
    }
    
    // Clear session
    session_unset();
    session_destroy();
    
    // Clear cookies
    setcookie('admin_logged_in', '', time() - 3600, '/');
    setcookie('csrf_token', '', time() - 3600, '/');
    
    // Redirect to homepage
    header('Location: index.php');
    exit;
}

// Handle login
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $login_error = 'Security token invalid. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        
        if (password_verify($password, DEFAULT_PASSWORD_HASH)) {
            $_SESSION['admin_logged_in'] = true;
            // Set files to writable for admin session
            $files = [POSTS_JSON, 'index.php', 'admin.php', 'robots.txt', 'sitemap.xml', SETTINGS_FILE];
            $directories = [POSTS_DIR, IMAGES_DIR, CACHE_DIR];
            
            foreach ($files as $file) {
                if (file_exists($file)) {
                    chmod($file, 0644);
                }
            }
            
            foreach ($directories as $dir) {
                if (file_exists($dir)) {
                    chmod($dir, 0755);
                    // Make files in directories writable too
                    $files_in_dir = glob($dir . '*');
                    foreach ($files_in_dir as $file) {
                        if (is_file($file)) {
                            chmod($file, 0644);
                        }
                    }
                }
            }
        } else {
            $login_error = 'Invalid password';
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    adminLogout();
}

// Load posts data
$posts = [];
if (file_exists(POSTS_JSON)) {
    $postsData = json_decode(file_get_contents(POSTS_JSON), true);
    $posts = $postsData['posts'] ?? [];
}

// Load settings
$settings = [
    'site_title' => 'MicroB CMS - Responsive Blog System',
    'site_description' => 'A minimal responsive blog system built with modern web technologies.',
    'hero_custom_code' => ''
];

if (file_exists(SETTINGS_FILE)) {
    $saved_settings = json_decode(file_get_contents(SETTINGS_FILE), true);
    if ($saved_settings) {
        $settings = array_merge($settings, $saved_settings);
    }
}

// Handle pagination
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$total_posts = count($posts);
$total_pages = ceil($total_posts / POSTS_PER_PAGE);
$offset = ($current_page - 1) * POSTS_PER_PAGE;
$paginated_posts = array_slice($posts, $offset, POSTS_PER_PAGE);

// Handle actions
$action_message = '';
$editing_post = null;
$post_content = '';

if (isLoggedIn()) {
    // Handle post editing
    if (isset($_GET['edit'])) {
        $edit_slug = validateAdminInput($_GET['edit'], 'slug');
        foreach ($posts as $post) {
            if ($post['slug'] === $edit_slug) {
                $editing_post = $post;
                $post_file = POSTS_DIR . $edit_slug . '.html';
                if (file_exists($post_file)) {
                    $post_content = file_get_contents($post_file);
                }
                break;
            }
        }
    }

    // Handle post reordering
    if (isset($_GET['move_up'])) {
        $slug = validateAdminInput($_GET['move_up'], 'slug');
        $index = null;
        foreach ($posts as $i => $post) {
            if ($post['slug'] === $slug) {
                $index = $i;
                break;
            }
        }
        if ($index !== null && $index > 0) {
            $temp = $posts[$index];
            $posts[$index] = $posts[$index - 1];
            $posts[$index - 1] = $temp;
            $postsData['posts'] = $posts;
            file_put_contents(POSTS_JSON, json_encode($postsData, JSON_PRETTY_PRINT));
            clearCache();
            $action_message = 'Post moved up successfully!';
            header('Location: admin.php');
            exit;
        }
    }

    if (isset($_GET['move_down'])) {
        $slug = validateAdminInput($_GET['move_down'], 'slug');
        $index = null;
        foreach ($posts as $i => $post) {
            if ($post['slug'] === $slug) {
                $index = $i;
                break;
            }
        }
        if ($index !== null && $index < count($posts) - 1) {
            $temp = $posts[$index];
            $posts[$index] = $posts[$index + 1];
            $posts[$index + 1] = $temp;
            $postsData['posts'] = $posts;
            file_put_contents(POSTS_JSON, json_encode($postsData, JSON_PRETTY_PRINT));
            clearCache();
            $action_message = 'Post moved down successfully!';
            header('Location: admin.php');
            exit;
        }
    }

    if (isset($_GET['reshuffle'])) {
        shuffle($posts);
        $postsData['posts'] = $posts;
        file_put_contents(POSTS_JSON, json_encode($postsData, JSON_PRETTY_PRINT));
        clearCache();
        $action_message = 'Posts reshuffled successfully!';
        header('Location: admin.php');
        exit;
    }

    // Handle clear cache
    if (isset($_GET['clear_cache'])) {
        clearCache();
        $action_message = 'Cache cleared successfully!';
        header('Location: admin.php');
        exit;
    }

    // Handle post actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $action_message = 'Security token invalid. Please try again.';
        } else {
            // Add new post
            if (isset($_POST['add_post'])) {
                $title = validateAdminInput($_POST['title'], 'title');
                $description = validateAdminInput($_POST['description'], 'text');
                $tags = validateAdminInput($_POST['tags'], 'tags');
                $featured_image = validateAdminInput($_POST['featured_image'], 'url');
                // Get content as raw HTML without validation to preserve all formatting
                $content = $_POST['content'] ?? '';
                
                $slug = generateUniqueSlug($title);
                
                $new_post = [
                    'title' => $title,
                    'slug' => $slug,
                    'description' => $description,
                    'tags' => $tags,
                    'featuredImage' => $featured_image,
                    'date' => date('Y-m-d H:i:s')
                ];
                
                // Add to beginning of posts array
                array_unshift($posts, $new_post);
                
                // First, write data to posts.json file
                $postsData['posts'] = $posts;
                $jsonWriteSuccess = file_put_contents(POSTS_JSON, json_encode($postsData, JSON_PRETTY_PRINT));
                
                if ($jsonWriteSuccess === false) {
                    $action_message = 'Error: Could not write to posts.json file!';
                } else {
                    // Then, create the HTML file only if JSON write was successful
                    if (!file_exists(POSTS_DIR)) {
                        mkdir(POSTS_DIR, 0755, true);
                    }
                    
                    $htmlWriteSuccess = file_put_contents(POSTS_DIR . $slug . '.html', $content);
                    
                    if ($htmlWriteSuccess === false) {
                        $action_message = 'Error: Post data saved but could not create HTML file!';
                        // Roll back the posts.json changes if HTML file creation fails
                        $posts = array_filter($posts, function($post) use ($slug) {
                            return $post['slug'] !== $slug;
                        });
                        $postsData['posts'] = $posts;
                        file_put_contents(POSTS_JSON, json_encode($postsData, JSON_PRETTY_PRINT));
                    } else {
                        $action_message = 'Post added successfully!';
                        clearCache();
                    }
                }
            }
            
            // Update post
            if (isset($_POST['update_post'])) {
                $original_slug = validateAdminInput($_POST['original_slug'], 'slug');
                $title = validateAdminInput($_POST['title'], 'title');
                $description = validateAdminInput($_POST['description'], 'text');
                $tags = validateAdminInput($_POST['tags'], 'tags');
                $featured_image = validateAdminInput($_POST['featured_image'], 'url');
                // Get content as raw HTML without validation to preserve all formatting
                $content = $_POST['content'] ?? '';
                
                $slug = ($title !== $posts[array_search($original_slug, array_column($posts, 'slug'))]['title']) 
                    ? generateUniqueSlug($title, $original_slug) 
                    : $original_slug;
                
                foreach ($posts as &$post) {
                    if ($post['slug'] === $original_slug) {
                        $post['title'] = $title;
                        $post['slug'] = $slug;
                        $post['description'] = $description;
                        $post['tags'] = $tags;
                        $post['featuredImage'] = $featured_image;
                        $post['date'] = date('Y-m-d H:i:s'); // Update date on edit
                        break;
                    }
                }
                
                // First, update posts.json file
                $postsData['posts'] = $posts;
                $jsonWriteSuccess = file_put_contents(POSTS_JSON, json_encode($postsData, JSON_PRETTY_PRINT));
                
                if ($jsonWriteSuccess === false) {
                    $action_message = 'Error: Could not update posts.json file!';
                } else {
                    // Then, update content file only if JSON write was successful
                    if ($original_slug !== $slug) {
                        $old_file = POSTS_DIR . $original_slug . '.html';
                        if (file_exists($old_file)) {
                            unlink($old_file);
                        }
                    }
                    
                    $htmlWriteSuccess = file_put_contents(POSTS_DIR . $slug . '.html', $content);
                    
                    if ($htmlWriteSuccess === false) {
                        $action_message = 'Error: Post data updated but could not update HTML file!';
                    } else {
                        $action_message = 'Post updated successfully!';
                        clearCache();
                    }
                }
            }
            
            // Generate robots.txt
            if (isset($_POST['generate_robots'])) {
                $robots_content = "User-agent: *\n";
                $robots_content .= "Allow: /\n";
                $robots_content .= "Disallow: /admin.php\n";
                $robots_content .= "Disallow: /cache/\n";
                $robots_content .= "Sitemap: " . (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/sitemap.xml\n";
                
                file_put_contents('robots.txt', $robots_content);
                $action_message = 'robots.txt generated successfully!';
            }
            
            // Generate sitemap.xml
            if (isset($_POST['generate_sitemap'])) {
                $base_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
                
                $sitemap_content = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                $sitemap_content .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
                
                // Homepage
                $sitemap_content .= "  <url>\n";
                $sitemap_content .= "    <loc>{$base_url}/</loc>\n";
                $sitemap_content .= "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
                $sitemap_content .= "    <changefreq>daily</changefreq>\n";
                $sitemap_content .= "    <priority>1.0</priority>\n";
                $sitemap_content .= "  </url>\n";
                
                // Posts
                foreach ($posts as $post) {
                    $sitemap_content .= "  <url>\n";
                    $sitemap_content .= "    <loc>{$base_url}/" . $post['slug'] . "</loc>\n";
                    $sitemap_content .= "    <lastmod>" . (isset($post['date']) ? substr($post['date'], 0, 10) : date('Y-m-d')) . "</lastmod>\n";
                    $sitemap_content .= "    <changefreq>monthly</changefreq>\n";
                    $sitemap_content .= "    <priority>0.8</priority>\n";
                    $sitemap_content .= "  </url>\n";
                }
                
                $sitemap_content .= '</urlset>';
                
                file_put_contents('sitemap.xml', $sitemap_content);
                $action_message = 'sitemap.xml generated successfully!';
            }
        }
    }

    // Handle post deletion
    if (isset($_GET['delete'])) {
        $slug_to_delete = validateAdminInput($_GET['delete'], 'slug');
        
        $posts = array_filter($posts, function($post) use ($slug_to_delete) {
            return $post['slug'] !== $slug_to_delete;
        });
        
        // First, update posts.json file
        $postsData['posts'] = array_values($posts);
        $jsonWriteSuccess = file_put_contents(POSTS_JSON, json_encode($postsData, JSON_PRETTY_PRINT));
        
        if ($jsonWriteSuccess === false) {
            $action_message = 'Error: Could not update posts.json file!';
        } else {
            // Then, delete the HTML file only if JSON write was successful
            $post_file = POSTS_DIR . $slug_to_delete . '.html';
            if (file_exists($post_file)) {
                unlink($post_file);
            }
            
            clearCache();
            $action_message = 'Post deleted successfully!';
            header('Location: admin.php');
            exit;
        }
    }

    // Handle image upload
    if (isset($_FILES['image_upload'])) {
        if (!file_exists(IMAGES_DIR)) {
            mkdir(IMAGES_DIR, 0755, true);
        }
        
        $file = $_FILES['image_upload'];
        // Use original filename without hash
        $filename = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $file['name']);
        $target_file = IMAGES_DIR . $filename;
        
        // Check if file already exists and create unique name if needed
        $counter = 1;
        $file_info = pathinfo($filename);
        $base_name = $file_info['filename'];
        $extension = isset($file_info['extension']) ? '.' . $file_info['extension'] : '';
        
        while (file_exists($target_file)) {
            $filename = $base_name . '_' . $counter . $extension;
            $target_file = IMAGES_DIR . $filename;
            $counter++;
        }
        
        // Check if image file is actual image
        $check = getimagesize($file['tmp_name']);
        if ($check !== false) {
            // Check file size (5MB max)
            if ($file['size'] <= 5000000) {
                // Check file extension
                $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
                if (in_array($imageFileType, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        $action_message = 'Image uploaded successfully!';
                    } else {
                        $action_message = 'Sorry, there was an error uploading your file.';
                    }
                } else {
                    $action_message = 'Sorry, only JPG, JPEG, PNG, GIF & WEBP files are allowed.';
                }
            } else {
                $action_message = 'Sorry, your file is too large (max 5MB).';
            }
        } else {
            $action_message = 'File is not an image.';
        }
    }
}

// Dashboard statistics
function getDashboardStats() {
    global $posts;
    $total_tags = [];
    foreach ($posts as $post) {
        if (isset($post['tags'])) {
            $total_tags = array_merge($total_tags, $post['tags']);
        }
    }
    $total_tags = array_unique($total_tags);
    
    $images_count = 0;
    if (file_exists(IMAGES_DIR)) {
        $images_count = count(glob(IMAGES_DIR . "*.{jpg,jpeg,png,gif,webp}", GLOB_BRACE));
    }
    
    return [
        'total_posts' => count($posts),
        'total_tags' => count($total_tags),
        'images_count' => $images_count,
        'recent_posts' => array_slice($posts, 0, 5)
    ];
}

$stats = getDashboardStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MicroBlog</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        .sidebar {
            width: 250px;
            height: 100vh;
            position: fixed;
        }
        .main-content {
            margin-left: 250px;
        }
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            animation: fadeIn 0.5s, fadeOut 0.5s 2.5s forwards;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        
        /* Enhanced Editor Styles */
        .editor-container {
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }
        .editor-toolbar {
            background: linear-gradient(to bottom, #f9fafb, #f3f4f6);
            border-bottom: 1px solid #d1d5db;
            padding: 0.75rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        .toolbar-group {
            display: flex;
            gap: 0.25rem;
            padding-right: 0.75rem;
            border-right: 1px solid #d1d5db;
        }
        .toolbar-group:last-child {
            border-right: none;
            padding-right: 0;
        }
        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            background: white;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 2.5rem;
            height: 2.5rem;
        }
        .toolbar-btn:hover {
            background: #f3f4f6;
            border-color: #9ca3af;
            transform: translateY(-1px);
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        .toolbar-btn.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        .editor-content {
            min-height: 400px;
            padding: 1.5rem;
            background: white;
            outline: none;
            line-height: 1.6;
            font-family: 'Inter', sans-serif;
            font-size: 16px;
        }
        .editor-content:focus {
            ring: 2px;
            ring-color: #3b82f6;
        }
        .editor-content h1, .editor-content h2, .editor-content h3 {
            margin-top: 1.5em;
            margin-bottom: 0.5em;
            font-weight: 600;
            line-height: 1.25;
        }
        .editor-content h1 {
            font-size: 2em;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 0.3em;
        }
        .editor-content h2 {
            font-size: 1.5em;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 0.3em;
        }
        .editor-content h3 {
            font-size: 1.25em;
        }
        .editor-content p {
            margin-bottom: 1em;
        }
        .editor-content ul, .editor-content ol {
            margin-bottom: 1em;
            padding-left: 2em;
        }
        .editor-content ul {
            list-style-type: disc;
        }
        .editor-content ol {
            list-style-type: decimal;
        }
        .editor-content li {
            margin-bottom: 0.5em;
        }
        .editor-content blockquote {
            border-left: 4px solid #3b82f6;
            padding-left: 1em;
            margin: 1em 0;
            font-style: italic;
            color: #6b7280;
        }
        .editor-content code {
            background: #f3f4f6;
            padding: 0.2em 0.4em;
            border-radius: 0.25em;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }
        .editor-content pre {
            background: #1f2937;
            color: #f9fafb;
            padding: 1em;
            border-radius: 0.5em;
            overflow-x: auto;
            margin: 1em 0;
        }
        .editor-content pre code {
            background: none;
            padding: 0;
            color: inherit;
        }
        .editor-content a {
            color: #3b82f6;
            text-decoration: underline;
        }
        .editor-content a:hover {
            color: #2563eb;
        }
        .editor-content img {
            max-width: 100%;
            height: auto;
            border-radius: 0.5em;
            margin: 1em 0;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            max-width: 90%;
            max-height: 90%;
            overflow: auto;
        }
        .modal-header {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        .modal-body {
            padding: 1rem;
        }
        .modal-footer {
            padding: 1rem;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        /* Drag and Drop Styles */
        .drop-zone {
            border: 2px dashed #d1d5db;
            border-radius: 0.5rem;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s;
            background: #f9fafb;
        }
        .drop-zone.dragover {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            max-height: 400px;
            overflow-y: auto;
        }
        .gallery-item {
            border: 2px solid transparent;
            border-radius: 0.5rem;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s;
        }
        .gallery-item:hover {
            border-color: #3b82f6;
            transform: scale(1.05);
        }
        .gallery-item.selected {
            border-color: #10b981;
        }
        .gallery-item img {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }
        .gallery-item-info {
            padding: 0.5rem;
            background: white;
        }

        /* Link Options Grid */
        .link-options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .link-option-group {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 0.5rem;
            border: 1px solid #e2e8f0;
        }
    </style>
</head>
<body class="bg-gray-100">
    <?php if (!isLoggedIn()): ?>
    <!-- Login Form -->
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8 bg-white p-8 rounded-lg shadow-md">
            <div>
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    MicroBlog Admin
                </h2>
                <p class="mt-2 text-center text-sm text-gray-600">
                    Sign in to manage your blog
                </p>
            </div>
            <form class="mt-8 space-y-6" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="rounded-md shadow-sm -space-y-px">
                    <div>
                        <label for="password" class="sr-only">Password</label>
                        <input id="password" name="password" type="password" required class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm" placeholder="Password">
                    </div>
                </div>

                <?php if ($login_error): ?>
                <div class="text-red-500 text-sm text-center">
                    <?php echo $login_error; ?>
                </div>
                <?php endif; ?>

                <div>
                    <button type="submit" name="login" class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Sign in
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php else: ?>
    <!-- Admin Dashboard -->
    <div class="flex">
        <!-- Sidebar -->
        <div class="sidebar bg-gray-800 text-white">
            <div class="p-4 border-b border-gray-700">
                <h1 class="text-xl font-bold">MicroBlog Admin</h1>
                <p class="text-sm text-gray-400">Content Management System</p>
            </div>
            <nav class="p-4">
                <ul>
                    <li class="mb-2">
                        <a href="#dashboard" class="block py-2 px-4 rounded bg-blue-700">
                            <i class="fas fa-tachometer-alt mr-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="mb-2">
                        <a href="#posts" class="block py-2 px-4 rounded hover:bg-gray-700">
                            <i class="fas fa-file-alt mr-2"></i> Posts
                        </a>
                    </li>
                    <li class="mb-2">
                        <a href="#add-post" class="block py-2 px-4 rounded hover:bg-gray-700">
                            <i class="fas fa-plus-circle mr-2"></i> Add New Post
                        </a>
                    </li>
                    <li class="mb-2">
                        <a href="#media" class="block py-2 px-4 rounded hover:bg-gray-700">
                            <i class="fas fa-image mr-2"></i> Media Library
                        </a>
                    </li>
                    <li class="mb-2">
                        <a href="#tools" class="block py-2 px-4 rounded hover:bg-gray-700">
                            <i class="fas fa-tools mr-2"></i> Tools
                        </a>
                    </li>
                    <li class="mb-2">
                        <a href="?logout" class="block py-2 px-4 rounded hover:bg-gray-700">
                            <i class="fas fa-sign-out-alt mr-2"></i> Logout
                        </a>
                    </li>
                </ul>
            </nav>
            <div class="absolute bottom-0 w-full p-4 border-t border-gray-700">
                <p class="text-xs text-gray-400"><?php echo $stats['total_posts']; ?> posts, <?php echo $stats['images_count']; ?> images</p>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content flex-1 p-8">
            <?php if ($action_message): ?>
            <div class="notification bg-green-500 text-white px-4 py-3 rounded shadow-lg">
                <?php echo $action_message; ?>
            </div>
            <?php endif; ?>

            <!-- Dashboard Stats -->
            <div id="dashboard" class="mb-8">
                <h2 class="text-2xl font-bold mb-6">Dashboard Overview</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 text-blue-500 mr-4">
                                <i class="fas fa-file-alt text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900"><?php echo $stats['total_posts']; ?></h3>
                                <p class="text-gray-500">Total Posts</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 text-green-500 mr-4">
                                <i class="fas fa-tags text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900"><?php echo $stats['total_tags']; ?></h3>
                                <p class="text-gray-500">Total Tags</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-purple-100 text-purple-500 mr-4">
                                <i class="fas fa-image text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900"><?php echo $stats['images_count']; ?></h3>
                                <p class="text-gray-500">Media Files</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Posts -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-4 border-b">
                        <h3 class="text-lg font-semibold">Recent Posts</h3>
                    </div>
                    <div class="p-4">
                        <?php if (count($stats['recent_posts']) > 0): ?>
                            <div class="space-y-3">
                                <?php foreach ($stats['recent_posts'] as $post): ?>
                                <div class="flex justify-between items-center py-2 border-b">
                                    <div>
                                        <h4 class="font-medium"><?php echo htmlspecialchars($post['title']); ?></h4>
                                        <p class="text-sm text-gray-500"><?php echo isset($post['date']) ? htmlspecialchars($post['date']) : 'No date'; ?></p>
                                    </div>
                                    <a href="?edit=<?php echo urlencode($post['slug']); ?>" class="text-blue-500 hover:text-blue-700">Edit</a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500">No posts yet. <a href="#add-post" class="text-blue-500">Add your first post</a>.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Posts List -->
            <div id="posts" class="bg-white rounded-lg shadow mb-8">
                <div class="p-4 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold">All Posts (<?php echo $stats['total_posts']; ?>)</h3>
                    <div class="flex space-x-2">
                        <a href="?reshuffle" class="inline-flex items-center px-3 py-1 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700" onclick="return confirm('Reshuffle all posts? This will change their order.')">
                            <i class="fas fa-random mr-1"></i> Reshuffle
                        </a>
                    </div>
                </div>
                <div class="p-4">
                    <?php if (count($paginated_posts) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order</th>
                                    <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                    <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tags</th>
                                    <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($paginated_posts as $index => $post): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <div class="flex space-x-1">
                                            <?php if ($offset + $index > 0): ?>
                                            <a href="?move_up=<?php echo urlencode($post['slug']); ?>" class="text-blue-500 hover:text-blue-700" title="Move Up">
                                                <i class="fas fa-arrow-up"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($offset + $index < $total_posts - 1): ?>
                                            <a href="?move_down=<?php echo urlencode($post['slug']); ?>" class="text-blue-500 hover:text-blue-700" title="Move Down">
                                                <i class="fas fa-arrow-down"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($post['title']); ?></div>
                                        <div class="text-sm text-gray-500">/<?php echo htmlspecialchars($post['slug']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo isset($post['date']) ? htmlspecialchars($post['date']) : 'N/A'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-wrap gap-1">
                                            <?php if (isset($post['tags']) && is_array($post['tags'])): ?>
                                                <?php foreach ($post['tags'] as $tag): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                        <?php echo htmlspecialchars($tag); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="?edit=<?php echo urlencode($post['slug']); ?>" class="text-blue-600 hover:text-blue-900 mr-3">Edit</a>
                                        <a href="?delete=<?php echo urlencode($post['slug']); ?>" class="text-red-600 hover:text-red-900" onclick="return confirm('Are you sure you want to delete this post?')">Delete</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="mt-4 flex justify-between items-center">
                        <div class="text-sm text-gray-500">
                            Showing <?php echo $offset + 1; ?> to <?php echo min($offset + POSTS_PER_PAGE, $total_posts); ?> of <?php echo $total_posts; ?> posts
                        </div>
                        <div class="flex space-x-1">
                            <?php if ($current_page > 1): ?>
                                <a href="?page=<?php echo $current_page - 1; ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50">Previous</a>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium <?php echo $i == $current_page ? 'bg-blue-500 text-white' : 'text-gray-500 hover:bg-gray-50'; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <a href="?page=<?php echo $current_page + 1; ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                    <p class="text-gray-500">No posts yet. <a href="#add-post" class="text-blue-500">Add your first post</a>.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add/Edit Post Form -->
            <div id="add-post" class="bg-white rounded-lg shadow mb-8">
                <div class="p-4 border-b">
                    <h3 class="text-lg font-semibold"><?php echo $editing_post ? 'Edit Post' : 'Add New Post'; ?></h3>
                </div>
                <div class="p-4">
                    <form method="POST" enctype="multipart/form-data" onsubmit="return prepareSubmit()">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <?php if ($editing_post): ?>
                            <input type="hidden" name="original_slug" value="<?php echo htmlspecialchars($editing_post['slug']); ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-1 gap-6">
                            <div>
                                <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Post Title</label>
                                <input type="text" name="title" id="title" required 
                                       value="<?php echo $editing_post ? htmlspecialchars($editing_post['title']) : ''; ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150">
                            </div>
                            
                            <div>
                                <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                                <textarea name="description" id="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150"><?php echo $editing_post ? htmlspecialchars($editing_post['description']) : ''; ?></textarea>
                            </div>
                            
                            <div>
                                <label for="tags" class="block text-sm font-medium text-gray-700 mb-2">Tags (comma separated)</label>
                                <input type="text" name="tags" id="tags" 
                                       value="<?php echo $editing_post && isset($editing_post['tags']) ? htmlspecialchars(implode(', ', $editing_post['tags'])) : ''; ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150">
                            </div>
                            
                            <div>
                                <label for="featured_image" class="block text-sm font-medium text-gray-700 mb-2">Featured Image URL</label>
                                <input type="text" name="featured_image" id="featured_image" 
                                       value="<?php echo $editing_post ? htmlspecialchars($editing_post['featuredImage'] ?? '') : ''; ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150">
                                <p class="mt-1 text-sm text-gray-500">You can upload an image in the Media Library below and copy the URL here</p>
                            </div>
                            
                            <!-- Enhanced Custom HTML Editor -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-3">Content</label>
                                
                                <div class="editor-container">
                                    <!-- Enhanced Toolbar -->
                                    <div class="editor-toolbar">
                                        <div class="toolbar-group">
                                            <button type="button" onclick="formatText('bold')" class="toolbar-btn" title="Bold">
                                                <i class="fas fa-bold"></i>
                                            </button>
                                            <button type="button" onclick="formatText('italic')" class="toolbar-btn" title="Italic">
                                                <i class="fas fa-italic"></i>
                                            </button>
                                            <button type="button" onclick="formatText('underline')" class="toolbar-btn" title="Underline">
                                                <i class="fas fa-underline"></i>
                                            </button>
                                        </div>
                                        
                                        <div class="toolbar-group">
                                            <button type="button" onclick="insertHeading(1)" class="toolbar-btn" title="Heading 1">
                                                <i class="fas fa-heading"></i><span class="ml-1 text-xs">1</span>
                                            </button>
                                            <button type="button" onclick="insertHeading(2)" class="toolbar-btn" title="Heading 2">
                                                <i class="fas fa-heading"></i><span class="ml-1 text-xs">2</span>
                                            </button>
                                            <button type="button" onclick="insertHeading(3)" class="toolbar-btn" title="Heading 3">
                                                <i class="fas fa-heading"></i><span class="ml-1 text-xs">3</span>
                                            </button>
                                        </div>
                                        
                                        <div class="toolbar-group">
                                            <button type="button" onclick="insertList('ul')" class="toolbar-btn" title="Bullet List">
                                                <i class="fas fa-list-ul"></i>
                                            </button>
                                            <button type="button" onclick="insertList('ol')" class="toolbar-btn" title="Numbered List">
                                                <i class="fas fa-list-ol"></i>
                                            </button>
                                        </div>
                                        
                                        <div class="toolbar-group">
                                            <button type="button" onclick="showLinkModal()" class="toolbar-btn" title="Insert Link">
                                                <i class="fas fa-link"></i>
                                            </button>
                                            <button type="button" onclick="showImageModal()" class="toolbar-btn" title="Insert Image">
                                                <i class="fas fa-image"></i>
                                            </button>
                                        </div>
                                        
                                        <div class="toolbar-group">
                                            <button type="button" onclick="insertCode()" class="toolbar-btn" title="Insert Code">
                                                <i class="fas fa-code"></i>
                                            </button>
                                            <button type="button" onclick="insertHTML()" class="toolbar-btn" title="Insert HTML">
                                                <i class="fab fa-html5"></i>
                                            </button>
                                        </div>
                                        
                                        <div class="toolbar-group">
                                            <button type="button" onclick="toggleView()" class="toolbar-btn" title="Toggle View" id="toggleViewBtn">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" onclick="clearEditor()" class="toolbar-btn text-red-600 hover:text-red-700" title="Clear Editor">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Enhanced Editor Content -->
                                    <div id="editor" 
                                         contenteditable="true"
                                         class="editor-content">
                                        <?php if ($editing_post && $post_content): ?>
                                            <?php echo $post_content; ?>
                                        <?php else: ?>
                                            <p>Start writing your post here...</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Hidden Field for Submission -->
                                <input type="hidden" name="content" id="hidden-content">
                            </div>
                            
                            <div class="flex gap-3 pt-4">
                                <?php if ($editing_post): ?>
                                    <button type="submit" name="update_post" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-150">
                                        <i class="fas fa-save mr-2"></i> Update Post
                                    </button>
                                    <a href="admin.php" class="inline-flex items-center px-6 py-3 border border-gray-300 text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition duration-150">
                                        <i class="fas fa-times mr-2"></i> Cancel
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="add_post" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150">
                                        <i class="fas fa-paper-plane mr-2"></i> Publish Post
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Media Library -->
            <div id="media" class="bg-white rounded-lg shadow mb-8">
                <div class="p-4 border-b">
                    <h3 class="text-lg font-semibold">Media Library</h3>
                </div>
                <div class="p-4">
                    <!-- Image Upload Form -->
                    <form method="POST" enctype="multipart/form-data" class="mb-6">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <div class="flex items-center gap-3">
                            <input type="file" name="image_upload" id="image_upload" accept="image/*" class="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-150">
                                <i class="fas fa-upload mr-2"></i> Upload Image
                            </button>
                        </div>
                    </form>

                    <!-- Display Uploaded Images -->
                    <h4 class="text-md font-medium mb-3">Uploaded Images</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                        <?php
                        if (file_exists(IMAGES_DIR)) {
                            $images = glob(IMAGES_DIR . "*.{jpg,jpeg,png,gif,webp}", GLOB_BRACE);
                            foreach ($images as $image) {
                                $image_url = $image;
                                $image_name = basename($image);
                                echo '<div class="border rounded-lg overflow-hidden bg-white shadow-sm hover:shadow-md transition-shadow">';
                                echo '<img src="' . $image_url . '" alt="' . $image_name . '" class="w-full h-32 object-cover">';
                                echo '<div class="p-3">';
                                echo '<p class="text-xs font-medium text-gray-700 truncate mb-2">' . $image_name . '</p>';
                                echo '<input type="text" value="' . $image_url . '" class="w-full text-xs px-2 py-1 border border-gray-300 rounded bg-gray-50 focus:outline-none focus:ring-1 focus:ring-blue-500" readonly onclick="this.select()">';
                                echo '</div></div>';
                            }
                        } else {
                            echo '<p class="text-gray-500 col-span-full">No images uploaded yet.</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Tools -->
            <div id="tools" class="bg-white rounded-lg shadow">
                <div class="p-4 border-b">
                    <h3 class="text-lg font-semibold">Tools</h3>
                </div>
                <div class="p-4">
                    <form method="POST" class="mb-6">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <div class="mb-4">
                            <h4 class="text-md font-medium mb-2">Generate robots.txt</h4>
                            <p class="text-sm text-gray-500 mb-3">Generate a robots.txt file for search engine optimization.</p>
                            <button type="submit" name="generate_robots" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-150">
                                <i class="fas fa-robot mr-2"></i> Generate robots.txt
                            </button>
                        </div>
                    </form>
                    
                    <form method="POST" class="mb-6">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <div class="mb-4">
                            <h4 class="text-md font-medium mb-2">Generate sitemap.xml</h4>
                            <p class="text-sm text-gray-500 mb-3">Generate a sitemap.xml file with homepage and all post URLs.</p>
                            <button type="submit" name="generate_sitemap" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-150">
                                <i class="fas fa-sitemap mr-2"></i> Generate sitemap.xml
                            </button>
                        </div>
                    </form>
                    
                    <div>
                        <h4 class="text-md font-medium mb-2">Clear Cache</h4>
                        <p class="text-sm text-gray-500 mb-3">Clear the posts cache to refresh the frontend display.</p>
                        <a href="?clear_cache" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 transition duration-150">
                            <i class="fas fa-broom mr-2"></i> Clear Cache
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Link Modal -->
    <div id="linkModal" class="modal">
        <div class="modal-content w-full max-w-2xl">
            <div class="modal-header">
                <h3 class="text-lg font-semibold">Insert Link</h3>
                <button type="button" onclick="closeLinkModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="space-y-6">
                    <!-- Basic Link Information -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Link URL *</label>
                            <input type="text" id="linkUrl" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="https://example.com" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Link Text</label>
                            <input type="text" id="linkText" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Click here">
                        </div>
                    </div>

                    <!-- Link Options Grid -->
                    <div class="link-options-grid">
                        <div class="link-option-group">
                            <h4 class="text-sm font-medium text-gray-900 mb-3">Link Behavior</h4>
                            <div class="space-y-2">
                                <label class="flex items-center">
                                    <input type="checkbox" id="linkNewTab" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 mr-2">
                                    <span class="text-sm text-gray-700">Open in new tab</span>
                                </label>
                            </div>
                        </div>

                        <div class="link-option-group">
                            <h4 class="text-sm font-medium text-gray-900 mb-3">SEO & Security</h4>
                            <div class="space-y-2">
                                <label class="flex items-center">
                                    <input type="checkbox" id="linkNoFollow" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 mr-2">
                                    <span class="text-sm text-gray-700">rel="nofollow"</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" id="linkNoOpener" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 mr-2">
                                    <span class="text-sm text-gray-700">rel="noopener"</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Links Section -->
                    <div class="border-t pt-4">
                        <h4 class="text-md font-medium text-gray-900 mb-3">Quick Links to Existing Posts</h4>
                        <div class="max-h-48 overflow-y-auto border border-gray-200 rounded-lg">
                            <div class="divide-y divide-gray-200">
                                <?php foreach ($posts as $post): ?>
                                <label class="flex items-center px-4 py-3 hover:bg-gray-50 cursor-pointer">
                                    <input type="radio" name="post_link" value="/<?php echo htmlspecialchars($post['slug']); ?>" class="post-link-radio rounded border-gray-300 text-blue-600 focus:ring-blue-500 mr-3">
                                    <div class="flex-1">
                                        <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($post['title']); ?></span>
                                        <p class="text-xs text-gray-500 mt-1">/<?php echo htmlspecialchars($post['slug']); ?></p>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeLinkModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition duration-150">Cancel</button>
                <button type="button" onclick="insertLink()" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md hover:bg-blue-700 transition duration-150">Insert Link</button>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="modal">
        <div class="modal-content w-4/5 max-w-6xl">
            <div class="modal-header">
                <h3 class="text-lg font-semibold">Insert Image</h3>
                <button type="button" onclick="closeImageModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Upload Section -->
                    <div class="space-y-6">
                        <div>
                            <h4 class="text-md font-medium mb-4">Upload New Image</h4>
                            <div id="dropZone" class="drop-zone">
                                <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                                <p class="text-gray-600 font-medium">Drag & drop your image here</p>
                                <p class="text-sm text-gray-500 mt-2">or</p>
                                <input type="file" id="imageUpload" accept="image/*" class="hidden">
                                <button type="button" onclick="document.getElementById('imageUpload').click()" class="mt-3 px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 transition duration-150">
                                    Choose File
                                </button>
                                <p class="text-xs text-gray-500 mt-3">Supported formats: JPG, JPEG, PNG, GIF, WEBP</p>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Alt Text (Recommended)</label>
                                <input type="text" id="imageAlt" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Description of the image for accessibility">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Image URL</label>
                                <input type="text" id="imageUrl" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="https://example.com/image.jpg">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Gallery Section -->
                    <div>
                        <h4 class="text-md font-medium mb-4">Choose from Gallery</h4>
                        <div class="image-gallery" id="imageGallery">
                            <?php
                            if (file_exists(IMAGES_DIR)) {
                                $images = glob(IMAGES_DIR . "*.{jpg,jpeg,png,gif,webp}", GLOB_BRACE);
                                foreach ($images as $image) {
                                    $image_url = $image;
                                    $image_name = basename($image);
                                    echo '<div class="gallery-item" data-url="' . $image_url . '" data-name="' . $image_name . '">';
                                    echo '<img src="' . $image_url . '" alt="' . $image_name . '" class="w-full h-32 object-cover">';
                                    echo '<div class="gallery-item-info">';
                                    echo '<p class="text-xs text-gray-600 truncate">' . $image_name . '</p>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                            } else {
                                echo '<p class="text-gray-500 col-span-full text-center py-8">No images in gallery</p>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeImageModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition duration-150">Cancel</button>
                <button type="button" onclick="insertImageFromModal()" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md hover:bg-blue-700 transition duration-150">Insert Image</button>
            </div>
        </div>
    </div>

    <!-- JavaScript for Enhanced Custom Editor -->
    <script>
        let isCodeView = false;
        let selectedImageUrl = '';
        let selectedImageAlt = '';
        
        function formatText(command) {
            document.execCommand(command, false, null);
            updateEditorFocus();
        }
        
        function insertHeading(level) {
            document.execCommand('formatBlock', false, `<h${level}>`);
            updateEditorFocus();
        }
        
        function insertList(type) {
            document.execCommand(type === 'ul' ? 'insertUnorderedList' : 'insertOrderedList', false, null);
            updateEditorFocus();
        }
        
        // Enhanced Link Functions
        function showLinkModal() {
            document.getElementById('linkModal').style.display = 'flex';
        }
        
        function closeLinkModal() {
            document.getElementById('linkModal').style.display = 'none';
            // Reset form
            document.getElementById('linkUrl').value = '';
            document.getElementById('linkText').value = '';
            document.getElementById('linkNewTab').checked = false;
            document.getElementById('linkNoFollow').checked = false;
            document.getElementById('linkNoOpener').checked = false;
            // Uncheck all post links
            document.querySelectorAll('.post-link-radio').forEach(radio => radio.checked = false);
        }
        
        function insertLink() {
            const urlInput = document.getElementById('linkUrl');
            const textInput = document.getElementById('linkText');
            let url = urlInput.value;
            const text = textInput.value || url;
            const newTab = document.getElementById('linkNewTab').checked;
            const noFollow = document.getElementById('linkNoFollow').checked;
            const noOpener = document.getElementById('linkNoOpener').checked;
            
            // Check if a post link is selected
            const selectedPostLink = document.querySelector('.post-link-radio:checked');
            if (selectedPostLink) {
                url = selectedPostLink.value;
                if (!urlInput.value) {
                    urlInput.value = url;
                }
            }
            
            if (url) {
                let relAttributes = [];
                if (noFollow) relAttributes.push('nofollow');
                if (noOpener) relAttributes.push('noopener');
                
                let linkHtml = `<a href="${url}"`;
                if (newTab) linkHtml += ' target="_blank"';
                if (relAttributes.length > 0) linkHtml += ` rel="${relAttributes.join(' ')}"`;
                linkHtml += `>${text}</a>`;
                
                // Use insertHTML to properly insert the link with all attributes
                document.execCommand('insertHTML', false, linkHtml);
                closeLinkModal();
                updateEditorFocus();
            } else {
                alert('Please enter a URL or select a post link.');
            }
        }
        
        // Enhanced Image Functions
        function showImageModal() {
            document.getElementById('imageModal').style.display = 'flex';
            selectedImageUrl = '';
            selectedImageAlt = '';
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').style.display = 'none';
            // Reset form
            document.getElementById('imageAlt').value = '';
            document.getElementById('imageUrl').value = '';
            // Clear selection
            document.querySelectorAll('.gallery-item').forEach(item => item.classList.remove('selected'));
        }
        
        function insertImageFromModal() {
            const alt = document.getElementById('imageAlt').value;
            const url = document.getElementById('imageUrl').value || selectedImageUrl;
            
            if (url) {
                const imgHtml = `<img src="${url}" alt="${alt || ''}" style="max-width: 100%; height: auto; border-radius: 0.5em; margin: 1em 0;">`;
                document.execCommand('insertHTML', false, imgHtml);
                closeImageModal();
                updateEditorFocus();
            } else {
                alert('Please select an image from the gallery or enter an image URL.');
            }
        }
        
        // Drag and Drop functionality
        function initializeDragAndDrop() {
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('imageUpload');
            
            // Prevent default drag behaviors
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
                document.body.addEventListener(eventName, preventDefaults, false);
            });
            
            // Highlight drop zone when item is dragged over it
            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, highlight, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, unhighlight, false);
            });
            
            // Handle dropped files
            dropZone.addEventListener('drop', handleDrop, false);
            
            // Handle file input change
            fileInput.addEventListener('change', handleFileSelect, false);
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            function highlight() {
                dropZone.classList.add('dragover');
            }
            
            function unhighlight() {
                dropZone.classList.remove('dragover');
            }
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                handleFiles(files);
            }
            
            function handleFileSelect(e) {
                const files = e.target.files;
                handleFiles(files);
            }
            
            function handleFiles(files) {
                if (files.length > 0) {
                    const file = files[0];
                    if (file.type.startsWith('image/')) {
                        uploadImage(file);
                    } else {
                        alert('Please select an image file.');
                    }
                }
            }
        }
        
        function uploadImage(file) {
            const formData = new FormData();
            formData.append('image_upload', file);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                // Reload the page to show the new image
                location.reload();
            })
            .catch(error => {
                console.error('Error uploading image:', error);
                alert('Error uploading image. Please try again.');
            });
        }
        
        // Gallery selection
        function initializeGallery() {
            const galleryItems = document.querySelectorAll('.gallery-item');
            galleryItems.forEach(item => {
                item.addEventListener('click', function() {
                    // Remove selection from all items
                    galleryItems.forEach(i => i.classList.remove('selected'));
                    // Add selection to clicked item
                    this.classList.add('selected');
                    // Set the selected image URL and alt text
                    selectedImageUrl = this.getAttribute('data-url');
                    selectedImageAlt = this.getAttribute('data-name');
                    document.getElementById('imageUrl').value = selectedImageUrl;
                    document.getElementById('imageAlt').value = selectedImageAlt;
                });
            });
        }
        
        // Quick links selection
        function initializeQuickLinks() {
            const postLinks = document.querySelectorAll('.post-link-radio');
            postLinks.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.checked) {
                        document.getElementById('linkUrl').value = this.value;
                    }
                });
            });
        }
        
        function insertCode() {
            const code = prompt("Enter code:");
            if (code) {
                document.execCommand('insertHTML', false, `<pre><code>${code}</code></pre>`);
            }
            updateEditorFocus();
        }
        
        function insertHTML() {
            const html = prompt("Enter HTML code:");
            if (html) {
                document.execCommand('insertHTML', false, html);
            }
            updateEditorFocus();
        }
        
        function toggleView() {
            const editor = document.getElementById('editor');
            const toggleBtn = document.getElementById('toggleViewBtn');
            
            if (!isCodeView) {
                // Switch to code view
                editor.textContent = editor.innerHTML;
                editor.style.fontFamily = 'Courier New, monospace';
                editor.style.fontSize = '14px';
                editor.style.backgroundColor = '#1f2937';
                editor.style.color = '#f9fafb';
                toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i>';
                toggleBtn.title = 'Switch to WYSIWYG View';
                isCodeView = true;
            } else {
                // Switch back to WYSIWYG view
                editor.innerHTML = editor.textContent;
                editor.style.fontFamily = 'Inter, sans-serif';
                editor.style.fontSize = '16px';
                editor.style.backgroundColor = 'white';
                editor.style.color = 'inherit';
                toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
                toggleBtn.title = 'Switch to Code View';
                isCodeView = false;
            }
            updateEditorFocus();
        }
        
        function clearEditor() {
            if (confirm('Are you sure you want to clear the editor? This cannot be undone.')) {
                document.getElementById('editor').innerHTML = '<p>Start writing your post here...</p>';
            }
            updateEditorFocus();
        }
        
        function updateEditorFocus() {
            const editor = document.getElementById('editor');
            editor.focus();
            
            // Scroll to cursor position
            const range = document.createRange();
            const selection = window.getSelection();
            range.selectNodeContents(editor);
            range.collapse(false);
            selection.removeAllRanges();
            selection.addRange(range);
        }
        
        function prepareSubmit() {
            const editor = document.getElementById('editor');
            // Get the raw HTML content from the editor
            document.getElementById('hidden-content').value = editor.innerHTML;
            return true;
        }
        
        // Enhanced editor initialization
        document.addEventListener('DOMContentLoaded', function() {
            const editor = document.getElementById('editor');
            if (editor) {
                // Set initial focus
                editor.focus();
                
                // Add paste event listener to handle rich text
                editor.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const text = (e.clipboardData || window.clipboardData).getData('text/plain');
                    document.execCommand('insertText', false, text);
                });
                
                // Add keyboard shortcuts
                editor.addEventListener('keydown', function(e) {
                    // Ctrl+B for bold
                    if (e.ctrlKey && e.key === 'b') {
                        e.preventDefault();
                        formatText('bold');
                    }
                    // Ctrl+I for italic
                    if (e.ctrlKey && e.key === 'i') {
                        e.preventDefault();
                        formatText('italic');
                    }
                    // Ctrl+U for underline
                    if (e.ctrlKey && e.key === 'u') {
                        e.preventDefault();
                        formatText('underline');
                    }
                });
            }
            
            // Initialize enhanced features
            initializeDragAndDrop();
            initializeGallery();
            initializeQuickLinks();
        });
    </script>
    <?php endif; ?>
</body>
</html>
