<?php
// Security headers
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// CSRF Protection
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// CSRF Validation for search form
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['csrf_token']) && isset($_GET['search'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        // Invalid CSRF token - clear search and continue without results
        $searchQuery = '';
        $_GET['search'] = '';
    }
}

// Input validation function
function validateInput($input, $type = 'string') {
    switch ($type) {
        case 'slug':
            return preg_match('/^[a-z0-9\-]+$/i', $input) ? $input : '';
        case 'alphanumeric':
            return preg_match('/^[a-zA-Z0-9\s\-_]+$/', $input) ? $input : '';
        case 'url':
            return filter_var($input, FILTER_VALIDATE_URL) ? $input : '';
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// Content sanitization function
function safe_content($content) {
    $allowed_tags = '<p><br><strong><em><b><i><u><a><img><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre><span><div>';
    $content = strip_tags($content, $allowed_tags);
    
    // Add safe attributes to allowed tags
    $content = preg_replace('/<a\s+([^>]*)>/i', '<a $1 rel="nofollow noopener noreferrer" target="_blank">', $content);
    $content = preg_replace('/<img\s+([^>]*)>/i', '<img $1 loading="lazy">', $content);
    
    return $content;
}

// Load posts data with caching
$postsCacheFile = 'cache/posts.cache';
$cacheTime = 86400; // 24 hours cache

if (file_exists($postsCacheFile) && (time() - filemtime($postsCacheFile) < $cacheTime)) {
    $postsData = json_decode(file_get_contents($postsCacheFile), true);
} else {
    $postsData = json_decode(file_get_contents('posts.json'), true);
    if (!file_exists('cache')) mkdir('cache', 0755, true);
    file_put_contents($postsCacheFile, json_encode($postsData));
}

$allPosts = $postsData['posts'] ?? [];

// Parse the URL path to determine the request type
$request = $_SERVER['REQUEST_URI'];
$base_path = str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']);
$clean_path = str_replace($base_path, '', $request);
$path_parts = explode('?', $clean_path);
$path_segments = explode('/', trim($path_parts[0], '/'));

// Initialize variables
$currentPost = null;
$filterTag = '';
$searchQuery = '';
$clean_path = '/';

// Determine request type based on path segments
if (count($path_segments) > 0 && !empty($path_segments[0])) {
    $possibleSlug = validateInput($path_segments[0], 'slug');
    
    if (!empty($possibleSlug)) {
        // Check if this is a post slug
        foreach ($allPosts as $post) {
            if (isset($post['slug']) && $post['slug'] === $possibleSlug) {
                $currentPost = $post;
                $clean_path = '/' . $possibleSlug;
                break;
            }
        }
        
        // If not a post, check if it's a tag
        if (!$currentPost) {
            $allTags = [];
            foreach ($allPosts as $post) {
                if (isset($post['tags'])) {
                    $allTags = array_merge($allTags, $post['tags']);
                }
            }
            $allTags = array_unique($allTags);
            
            if (in_array($possibleSlug, $allTags)) {
                $filterTag = $possibleSlug;
                $clean_path = '/' . $possibleSlug;
            } else {
                // Otherwise treat it as a search term
                $searchQuery = validateInput($possibleSlug, 'alphanumeric');
                $clean_path = '/' . $possibleSlug;
            }
        }
    }
}

// If we have a current post from the URL parsing, load its content
if ($currentPost) {
    $contentFile = "posts/" . validateInput($currentPost['slug'], 'slug') . ".html";
    if (file_exists($contentFile)) {
        $currentPost['content'] = safe_content(file_get_contents($contentFile));
    } else {
        $currentPost['content'] = "<p>Post content not available.</p>";
    }
}

// Process posts filtering based on tag or search
$currentPosts = $allPosts;

if (!empty($searchQuery)) {
    $filteredPosts = array_filter($currentPosts, function($post) use ($searchQuery) {
        $searchQueryLower = strtolower($searchQuery);
        $tags = isset($post['tags']) ? array_map('strtolower', $post['tags']) : [];
        $title = isset($post['title']) ? strtolower($post['title']) : '';
        $description = isset($post['description']) ? strtolower($post['description']) : '';
        
        return in_array($searchQueryLower, $tags) || 
               strpos($title, $searchQueryLower) !== false || 
               strpos($description, $searchQueryLower) !== false;
    });
    $currentPosts = array_values($filteredPosts);
} elseif (!empty($filterTag)) {
    $filteredPosts = array_filter($currentPosts, function($post) use ($filterTag) {
        return isset($post['tags']) && in_array($filterTag, $post['tags']);
    });
    $currentPosts = array_values($filteredPosts);
}

// Function to generate URLs for the new structure
function generateUrl($type = '', $value = '') {
    $base_url = 'https://' . $_SERVER['HTTP_HOST'] . str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']);
    
    if (!empty($value)) {
        return $base_url . '/' . urlencode($value);
    }
    
    return $base_url;
}

// Function to output safe HTML
function safe_html($data) {
    if (is_array($data)) return '';
    return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
}

// Generate meta description
function generateMetaDescription($post, $default) {
    if ($post && isset($post['description'])) {
        return safe_html(substr($post['description'], 0, 160));
    }
    return $default;
}

// Generate page title
function generatePageTitle($post, $default) {
    if ($post && isset($post['title'])) {
        return safe_html($post['title']) . ' - MicroB CMS';
    }
    return $default;
}

// Get all unique tags for navigation
$allTags = [];
foreach ($allPosts as $post) {
    if (isset($post['tags'])) {
        $allTags = array_merge($allTags, $post['tags']);
    }
}
$allTags = array_unique($allTags);

// Get recent posts for hero carousel (5 most recent)
$recentPosts = array_slice($allPosts, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo generatePageTitle($currentPost, 'MicroB CMS - Responsive Blog System'); ?></title>
    <meta name="description" content="<?php echo generateMetaDescription($currentPost, 'Minimalistic responsive blog content management system built with modern web technologies optimized for SEO and speed'); ?>">
    <link rel="icon" type="image/x-icon" href="<?php echo generateUrl(); ?>/icon.png">
    <!-- Add these favicon links -->
    <link rel="shortcut icon" href="<?php echo generateUrl(); ?>/icon.png" type="image/png">
    <link rel="icon" href="<?php echo generateUrl(); ?>/icon.png" type="image/png">
    
    <!-- Canonical URL -->
    <link rel="canonical" href="<?php 
        if ($currentPost) {
            echo generateUrl('post', $currentPost['slug']);
        } else {
            echo generateUrl();
        }
    ?>">
    
    <!-- Open Graph Tags -->
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?php echo generatePageTitle($currentPost, 'MicroB CMS - Responsive Blog System'); ?>">
    <meta property="og:description" content="<?php echo generateMetaDescription($currentPost, 'Minimalistic responsive blog content management system built with modern web technologies optimized for SEO and speed'); ?>">
    <meta property="og:url" content="<?php 
        if ($currentPost) {
            echo generateUrl('post', $currentPost['slug']);
        } else {
            echo generateUrl();
        }
    ?>">
    <meta property="og:site_name" content="MicroB CMS">
    <?php if ($currentPost && isset($currentPost['featuredImage'])): ?>
    <meta property="og:image" content="<?php echo safe_html($currentPost['featuredImage']); ?>">
    <?php else: ?>
    <meta property="og:image" content="<?php echo generateUrl(); ?>/logo.png">
    <?php endif; ?>
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo generatePageTitle($currentPost, 'MicroB CMS - Responsive Blog System'); ?>">
    <meta name="twitter:description" content="<?php echo generateMetaDescription($currentPost, 'Minimalistic responsive content management system built with modern web technologies optimized for blogging, SEO and speed'); ?>">
    <?php if ($currentPost && isset($currentPost['featuredImage'])): ?>
    <meta name="twitter:image" content="<?php echo safe_html($currentPost['featuredImage']); ?>">
    <?php else: ?>
    <meta name="twitter:image" content="<?php echo generateUrl(); ?>/logo.png">
    <?php endif; ?>
    
    <!-- JSON-LD Structured Data -->
    <?php if ($currentPost): ?>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "BlogPosting",
        "mainEntityOfPage": {
            "@type": "WebPage",
            "@id": "<?php echo generateUrl('post', $currentPost['slug']); ?>"
        },
        "headline": "<?php echo safe_html($currentPost['title'] ?? ''); ?>",
        "description": "<?php echo safe_html($currentPost['description'] ?? ''); ?>",
        "image": "<?php echo safe_html($currentPost['featuredImage'] ?? generateUrl() . '/logo.png'); ?>",
        "author": {
            "@type": "Organization",
            "name": "MicroB CMS"
        },
        "publisher": {
            "@type": "Organization",
            "name": "MicroB CMS",
            "logo": {
                "@type": "ImageObject",
                "url": "<?php echo generateUrl(); ?>/logo.png"
            }
        },
        "datePublished": "<?php echo date('Y-m-d\TH:i:s\+00:00'); ?>",
        "dateModified": "<?php echo date('Y-m-d\TH:i:s\+00:00'); ?>"
    }
    </script>
    <?php else: ?>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebSite",
        "name": "MicroB CMS",
        "url": "<?php echo generateUrl(); ?>",
        "potentialAction": {
            "@type": "SearchAction",
            "target": "<?php echo generateUrl(); ?>/{search_term_string}",
            "query-input": "required name=search_term_string"
        }
    }
    </script>
    <?php endif; ?>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com/3.4.1"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        dark: {
                            100: '#1e293b',
                            200: '#172033',
                            300: '#0f172a',
                        }
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-out forwards',
                        'slide-up': 'slideUp 0.5s ease-out forwards',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(20px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            transition: background-color 0.3s, color 0.3s;
            min-height: 100vh;
        }
        .card {
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .post-content {
            display: none;
        }
        .scroll-to-top {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.3s, transform 0.3s;
        }
        .scroll-to-top.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .loading-bar {
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #ec4899);
            background-size: 200% 200%;
            animation: loadingAnimation 2s ease infinite;
        }
        @keyframes loadingAnimation {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        /* Lazy loading styles */
        .lazy-image {
            opacity: 0;
            transition: opacity 0.3s;
        }
        .lazy-image.loaded {
            opacity: 1;
        }
        
        /* Tag styles */
        .tag {
            transition: all 0.2s ease;
        }
        .tag:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        /* Hero carousel styles */
        .carousel-slide {
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
            position: absolute;
            width: 100%;
        }
        .carousel-slide.active {
            opacity: 1;
            position: relative;
        }

        /* Navigation spacing */
        .main-nav {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }
        .dark .main-nav {
            border-top-color: #374151;
        }

        /* Logo styling */
        .site-logo {
            max-height: 40px;
            width: auto;
        }

        /* Carousel z-index fix */
        .carousel-prev, .carousel-next {
            z-index: 20;
        }

        /* Header z-index adjustment */
        header {
            z-index: 30;
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-dark-300 text-gray-800 dark:text-gray-200">
    <!-- Header -->
    <header class="bg-white dark:bg-dark-100 shadow-md sticky top-0 z-10">
        <div class="container mx-auto px-4 py-4">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="flex items-center mb-4 md:mb-0">
                    <a href="<?php echo generateUrl(); ?>" class="flex items-center">
                        <img src="<?php echo generateUrl(); ?>/icon.png" alt="MicroB CMS Logo" class="site-logo mr-3" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'120\' height=\'40\' viewBox=\'0 0 120 40\'%3E%3Crect width=\'120\' height=\'40\' fill=\'%233b82f6\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' dominant-baseline=\'middle\' text-anchor=\'middle\' font-family=\'Arial\' font-size=\'14\' fill=\'white\'%3EMicroB CMS%3C/text%3E%3C/svg%3E'">
                        <div>
                            <?php if ($currentPost): ?>
                            <h2 class="text-xl font-bold">MicroB CMS</h2>
                            <?php else: ?>
                            <h1 class="text-xl font-bold">MicroB CMS</h1>
                            <?php endif; ?>
                            <p class="text-sm text-gray-500 dark:text-gray-400" id="site-description">
                                <?php echo $currentPost ? safe_html($currentPost['description'] ?? '') : 'Micro blog content management system'; ?>
                            </p>
                        </div>
                    </a>
                </div>
                
                <div class="flex items-center space-x-4">
                    <form method="GET" action="<?php echo generateUrl(); ?>" class="relative" id="search-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="text" name="search" placeholder="Search posts..." value="<?php echo safe_html($searchQuery); ?>" class="px-4 py-2 rounded-full border border-gray-300 dark:border-gray-700 bg-white dark:bg-dark-200 focus:outline-none focus:ring-2 focus:ring-blue-500 w-40 md:w-64" aria-label="Search posts" id="search-input">
                        <button type="submit" class="absolute right-3 top-2 text-gray-400" aria-label="Search">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                    <button id="theme-toggle" class="w-10 h-10 rounded-full bg-gray-200 dark:bg-dark-200 flex items-center justify-center" aria-label="Toggle dark mode">
                        <i class="fas fa-moon text-yellow-500"></i>
                    </button>
                </div>
            </div>

            <nav class="main-nav" aria-label="Main navigation">
                <ul class="flex space-x-6 overflow-x-auto pb-2">
                    <li><a href="<?php echo generateUrl(); ?>" class="<?php echo !$currentPost && empty($searchQuery) && empty($filterTag) ? 'text-blue-500 font-medium' : 'hover:text-blue-500'; ?>">Home</a></li>
                    <?php foreach ($allTags as $tag): ?>
                    <li><a href="<?php echo generateUrl('tag', $tag); ?>" class="<?php echo $filterTag === $tag ? 'text-blue-500 font-medium' : 'hover:text-blue-500'; ?>"><?php echo safe_html($tag); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Hero Carousel -->
    <?php if (!$currentPost && count($recentPosts) > 0): ?>
    <section class="bg-gradient-to-r from-blue-500 to-purple-600 text-white py-12" aria-labelledby="hero-heading">
        <div class="container mx-auto px-4">
            <div class="relative max-w-6xl mx-auto">
                <div class="carousel-container relative overflow-hidden rounded-lg shadow-2xl">
                    <?php foreach ($recentPosts as $index => $post): ?>
                    <div class="carousel-slide <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                        <a href="<?php echo generateUrl('post', $post['slug']); ?>" class="block">
                            <div class="relative h-64 lg:h-80">
                                <img 
                                    src="<?php echo generateUrl(); ?>/logo.png" 
                                    data-src="<?php echo safe_html($post['featuredImage'] ?? generateUrl() . '/logo.png'); ?>" 
                                    alt="<?php echo safe_html($post['title'] ?? ''); ?>" 
                                    class="lazy-image w-full h-full object-cover rounded-lg"
                                    onerror="this.src='<?php echo generateUrl(); ?>/logo.png'"
                                >
                                <div class="absolute inset-0 bg-black/40 flex items-center justify-center">
                                    <h3 class="text-2xl lg:text-3xl font-bold text-center px-4"><?php echo safe_html($post['title'] ?? ''); ?></h3>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Carousel Indicators -->
                <div class="flex justify-center space-x-2 mt-6">
                    <?php foreach ($recentPosts as $index => $post): ?>
                    <button class="carousel-indicator w-3 h-3 rounded-full bg-white/40 <?php echo $index === 0 ? 'bg-white' : ''; ?>" data-index="<?php echo $index; ?>" aria-label="Go to slide <?php echo $index + 1; ?>"></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8" id="main-content" role="main">
        <?php if (!$currentPost): ?>
        <!-- Search/Tag Info -->
        <?php if (!empty($searchQuery) || !empty($filterTag)): ?>
        <div class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
            <h2 class="text-lg font-semibold mb-2">
                <?php if (!empty($searchQuery)): ?>
                Search Results for "<?php echo safe_html($searchQuery); ?>"
                <?php else: ?>
                Posts tagged with "<?php echo safe_html($filterTag); ?>"
                <?php endif; ?>
            </h2>
            <a href="<?php echo generateUrl(); ?>" class="text-blue-500 hover:text-blue-700 text-sm">← Back to all posts</a>
        </div>
        <?php endif; ?>
        
        <!-- Posts Grid -->
        <section aria-labelledby="posts-heading">
            <h2 id="posts-heading" class="sr-only">Blog Posts</h2>
            <?php if (count($currentPosts) > 0): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="posts-grid">
                <?php foreach ($currentPosts as $post): ?>
                <article class="card bg-white dark:bg-dark-100 rounded-lg overflow-hidden shadow-md animate-slide-up" onclick="window.location='<?php echo generateUrl('post', $post['slug']); ?>'">
                    <img 
                        src="<?php echo generateUrl(); ?>/logo.png" 
                        data-src="<?php echo safe_html($post['featuredImage'] ?? generateUrl() . '/logo.png'); ?>" 
                        alt="<?php echo safe_html($post['title'] ?? ''); ?>" 
                        class="lazy-image w-full h-48 object-cover"
                        onerror="this.src='<?php echo generateUrl(); ?>/logo.png'"
                    >
                    <div class="p-4">
                        <h2 class="text-xl font-bold mb-2"><?php echo safe_html($post['title'] ?? ''); ?></h2>
                        <p class="text-gray-600 dark:text-gray-400 mb-4"><?php echo safe_html($post['description'] ?? ''); ?></p>
                        <div class="flex flex-wrap gap-2 mb-4">
                            <?php if (isset($post['tags'])): ?>
                            <?php foreach ($post['tags'] as $tag): ?>
                            <a href="<?php echo generateUrl('tag', $tag); ?>" class="tag bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-100 text-xs px-2 py-1 rounded hover:no-underline" onclick="event.stopPropagation();"><?php echo safe_html($tag); ?></a>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-search text-4xl text-gray-400 mb-4"></i>
                <h3 class="text-xl font-semibold mb-2">No posts found</h3>
                <p class="text-gray-600 dark:text-gray-400">Try a different search term or browse all posts.</p>
                <a href="<?php echo generateUrl(); ?>" class="inline-block mt-4 px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600">View All Posts</a>
            </div>
            <?php endif; ?>
        </section>
        
        <!-- Ads Row - Reduced margin -->
        <aside aria-labelledby="ads-heading" class="grid grid-cols-2 md:grid-cols-4 gap-4 my-4" id="ads-row">
            <h2 id="ads-heading" class="sr-only">Advertisements</h2>
            <!-- Ads will be loaded here by JavaScript -->
        </aside>
        
        <?php else: ?>
        <!-- Single Post View -->
        <article class="max-w-4xl mx-auto bg-white dark:bg-dark-100 rounded-lg shadow-md overflow-hidden">
            <img 
                src="<?php echo generateUrl(); ?>/logo.png" 
                data-src="<?php echo safe_html($currentPost['featuredImage'] ?? generateUrl() . '/logo.png'); ?>" 
                alt="<?php echo safe_html($currentPost['title'] ?? ''); ?>" 
                class="lazy-image w-full h-64 object-cover"
                onerror="this.src='<?php echo generateUrl(); ?>/logo.png'"
            >
            <div class="p-6">
                <h1 class="text-3xl font-bold mb-4"><?php echo safe_html($currentPost['title'] ?? ''); ?></h1>
                <div class="flex flex-wrap gap-2 mb-6">
                    <?php if (isset($currentPost['tags'])): ?>
                    <?php foreach ($currentPost['tags'] as $tag): ?>
                    <a href="<?php echo generateUrl('tag', $tag); ?>" class="tag bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-100 text-sm px-3 py-1 rounded hover:no-underline"><?php echo safe_html($tag); ?></a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="prose dark:prose-invert max-w-none">
                    <?php echo $currentPost['content'] ?? '<p>Post content not available.</p>'; ?>
                </div>
            </div>
        </article>

        <div class="max-w-4xl mx-auto mt-6">
            <h2 class="text-2xl font-bold mb-6">Related Posts</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6" id="related-posts">
                <!-- Related posts will be loaded here -->
                <?php
                $relatedCount = 0;
                foreach ($allPosts as $post) {
                    if ($post['slug'] !== $currentPost['slug'] && $relatedCount < 2) {
                        $relatedCount++;
                        ?>
                        <article class="card bg-white dark:bg-dark-100 rounded-lg overflow-hidden shadow-md" onclick="window.location='<?php echo generateUrl('post', $post['slug']); ?>'">
                            <img 
                                src="<?php echo generateUrl(); ?>/logo.png" 
                                data-src="<?php echo safe_html($post['featuredImage'] ?? generateUrl() . '/logo.png'); ?>" 
                                alt="<?php echo safe_html($post['title'] ?? ''); ?>" 
                                class="lazy-image w-full h-40 object-cover"
                                onerror="this.src='<?php echo generateUrl(); ?>/logo.png'"
                            >
                            <div class="p-4">
                                <h3 class="text-lg font-bold mb-2"><?php echo safe_html($post['title'] ?? ''); ?></h3>
                                <p class="text-gray-600 dark:text-gray-400 text-sm mb-4"><?php echo substr(safe_html($post['description'] ?? ''), 0, 100); ?>...</p>
                            </div>
                        </article>
                        <?php
                    }
                }
                ?>
            </div>
        </div>

        <!-- Ads Row for Single Post - MOVED BELOW RELATED POSTS with reduced margin -->
        <aside aria-labelledby="single-post-ads-heading" class="grid grid-cols-2 md:grid-cols-4 gap-4 my-4 max-w-4xl mx-auto" id="single-post-ads">
            <h2 id="single-post-ads-heading" class="sr-only">Advertisements</h2>
            <!-- Ads will be loaded here by JavaScript -->
        </aside>
        
        <div class="max-w-4xl mx-auto mt-6">
            <a href="<?php echo generateUrl(); ?>" class="flex items-center text-blue-500 font-medium hover:text-blue-700" aria-label="Back to all posts">
                <i class="fas fa-arrow-left mr-2" aria-hidden="true"></i> Back to all posts
            </a>
        </div>
        <?php endif; ?>
    </main>

    <!-- Scroll to top button -->
    <button class="scroll-to-top fixed bottom-6 right-6 w-14 h-14 rounded-full bg-blue-500 text-white shadow-lg flex items-center justify-center hover:bg-blue-600 transition-colors" aria-label="Scroll to top">
        <i class="fas fa-arrow-up text-xl"></i>
    </button>

    <!-- Footer -->
    <footer class="bg-white dark:bg-dark-100 border-t border-gray-200 dark:border-gray-700 mt-8" role="contentinfo">
        <div class="container mx-auto px-4 py-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    <h3 class="text-lg font-bold mb-4">MicroB CMS</h3>
                    <p class="text-gray-600 dark:text-gray-400">Micro blog CMS built for bloggers with speed and SEO in mind</p>
                </div>
                <div>
                    <h3 class="text-lg font-bold mb-4">Quick Links</h3>
                    <ul class="space-y-2">
                        <li><a href="#" class="text-gray-600 dark:text-gray-400 hover:text-blue-500">About Us</a></li>
                        <li><a href="#" class="text-gray-600 dark:text-gray-400 hover:text-blue-500">Contact</a></li>
                        <li><a href="#" class="text-gray-600 dark:text-gray-400 hover:text-blue-500">Privacy Policy</a></li>
                        <li><a href="#" class="text-gray-600 dark:text-gray-400 hover:text-blue-500">Terms of Service</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-lg font-bold mb-4">Categories</h3>
                    <ul class="space-y-2">
                        <?php foreach (array_slice($allTags, 0, 4) as $tag): ?>
                        <li><a href="<?php echo generateUrl('tag', $tag); ?>" class="text-gray-600 dark:text-gray-400 hover:text-blue-500"><?php echo safe_html($tag); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <h3 class="text-lg font-bold mb-4">Connect</h3>
                    <div class="flex space-x-4">
                        <a href="#" class="text-gray-600 dark:text-gray-400 hover:text-blue-500" aria-label="Twitter"><i class="fab fa-twitter fa-lg"></i></a>
                        <a href="#" class="text-gray-600 dark:text-gray-400 hover:text-blue-500" aria-label="Facebook"><i class="fab fa-facebook fa-lg"></i></a>
                        <a href="#" class="text-gray-600 dark:text-gray-400 hover:text-blue-500" aria-label="Instagram"><i class="fab fa-instagram fa-lg"></i></a>
                        <a href="#" class="text-gray-600 dark:text-gray-400 hover:text-blue-500" aria-label="GitHub"><i class="fab fa-github fa-lg"></i></a>
                        <a href="#" class="text-gray-600 dark:text-gray-400 hover:text-blue-500" aria-label="LinkedIn"><i class="fab fa-linkedin fa-lg"></i></a>
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-200 dark:border-gray-700 mt-8 pt-8 text-center text-gray-500 dark:text-gray-400">
                <p>&copy; <?php echo date('Y'); ?> MicroB CMS. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        // DOM elements
        const scrollToTopBtn = document.querySelector('.scroll-to-top');
        const themeToggle = document.getElementById('theme-toggle');
        const searchForm = document.getElementById('search-form');
        const searchInput = document.getElementById('search-input');

        // Sample ads data with 1:1 ratio images
        const adsData = [
            { url: "#", image: "https://cdn.pixabay.com/photo/2016/11/30/12/16/question-mark-1872665_1280.jpg", title: "Web Hosting" },
            { url: "#", image: "https://cdn.pixabay.com/photo/2017/07/10/23/43/question-mark-2492009_1280.jpg", title: "SEO Tools" },
            { url: "#", image: "https://cdn.pixabay.com/photo/2018/09/24/08/31/pixel-3699332_1280.png", title: "Web Development Course" },
            { url: "#", image: "https://cdn.pixabay.com/photo/2016/06/13/09/57/white-1454125_1280.jpg", title: "Domain Names" }
        ];

        // Carousel variables
        let currentSlide = 0;
        const slides = document.querySelectorAll('.carousel-slide');
        const indicators = document.querySelectorAll('.carousel-indicator');
        const totalSlides = slides.length;

        // Initialize the blog when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            loadAds();
            setupEventListeners();
            checkThemePreference();
            lazyLoadImages();
            initializeCarousel();
        });

        // Initialize carousel with proper display
        function initializeCarousel() {
            if (totalSlides > 0) {
                // Set initial display state
                slides.forEach((slide, index) => {
                    if (index !== currentSlide) {
                        slide.style.display = 'none';
                    } else {
                        slide.style.display = 'block';
                    }
                });
                
                // Auto-advance carousel every 5 seconds
                setInterval(nextSlide, 5000);
            }
        }

        // Carousel functions
        function nextSlide() {
            currentSlide = (currentSlide + 1) % totalSlides;
            updateCarousel();
        }

        function prevSlide() {
            currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
            updateCarousel();
        }

        function goToSlide(index) {
            currentSlide = index;
            updateCarousel();
        }

        function updateCarousel() {
            slides.forEach((slide, index) => {
                slide.classList.toggle('active', index === currentSlide);
                // Hide all slides except active one
                if (index !== currentSlide) {
                    slide.style.display = 'none';
                } else {
                    slide.style.display = 'block';
                }
            });
            indicators.forEach((indicator, index) => {
                indicator.classList.toggle('bg-white', index === currentSlide);
                indicator.classList.toggle('bg-white/40', index !== currentSlide);
            });
        }

        // Load ads
        function loadAds() {
            // Load ads for homepage
            const adsRow = document.getElementById('ads-row');
            if (adsRow) {
                adsData.forEach(ad => {
                    const adElement = document.createElement('a');
                    adElement.href = ad.url;
                    adElement.target = "_blank";
                    adElement.rel = "nofollow noopener noreferrer";
                    adElement.className = 'block rounded-lg overflow-hidden shadow-md transition-transform hover:scale-105 aspect-square';
                    adElement.innerHTML = `
                        <img 
                            src="<?php echo generateUrl(); ?>/logo.png" 
                            data-src="${ad.image}" 
                            alt="${ad.title}" 
                            class="lazy-image w-full h-full object-cover"
                            onerror="this.src='<?php echo generateUrl(); ?>/logo.png'"
                        >
                    `;
                    adsRow.appendChild(adElement);
                });
            }
            
            // Load ads for single post
            const singlePostAds = document.getElementById('single-post-ads');
            if (singlePostAds) {
                adsData.forEach(ad => {
                    const adElement = document.createElement('a');
                    adElement.href = ad.url;
                    adElement.target = "_blank";
                    adElement.rel = "nofollow noopener noreferrer";
                    adElement.className = 'block rounded-lg overflow-hidden shadow-md transition-transform hover:scale-105 aspect-square';
                    adElement.innerHTML = `
                        <img 
                            src="<?php echo generateUrl(); ?>/logo.png" 
                            data-src="${ad.image}" 
                            alt="${ad.title}" 
                            class="lazy-image w-full h-full object-cover"
                            onerror="this.src='<?php echo generateUrl(); ?>/logo.png'"
                        >
                    `;
                    singlePostAds.appendChild(adElement);
                });
            }
            
            // Lazy load ad images
            lazyLoadImages();
        }

        // Lazy load images
        function lazyLoadImages() {
            const lazyImages = document.querySelectorAll('.lazy-image');
            
            if ('IntersectionObserver' in window) {
                const imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            img.src = img.dataset.src;
                            img.classList.add('loaded');
                            imageObserver.unobserve(img);
                        }
                    });
                });
                
                lazyImages.forEach(img => imageObserver.observe(img));
            } else {
                // Fallback for browsers without IntersectionObserver
                lazyImages.forEach(img => {
                    img.src = img.dataset.src;
                    img.classList.add('loaded');
                });
            }
        }

        // Setup event listeners
        function setupEventListeners() {
            // Theme toggle
            themeToggle.addEventListener('click', toggleTheme);
            
            // Scroll to top button
            if (scrollToTopBtn) {
                scrollToTopBtn.addEventListener('click', () => {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
                
                // Show/hide scroll to top button on scroll
                window.addEventListener('scroll', () => {
                    if (window.scrollY > 500) {
                        scrollToTopBtn.classList.add('visible');
                    } else {
                        scrollToTopBtn.classList.remove('visible');
                    }
                });
            }
            
            // Handle search form submission to redirect to clean URL
            if (searchForm && searchInput) {
                searchForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const searchTerm = searchInput.value.trim();
                    if (searchTerm) {
                        window.location.href = '<?php echo generateUrl(); ?>/' + encodeURIComponent(searchTerm);
                    }
                });
            }

            // Carousel event listeners
            indicators.forEach((indicator, index) => {
                indicator.addEventListener('click', () => goToSlide(index));
            });
        }

        // Toggle theme
        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                themeToggle.innerHTML = '<i class="fas fa-moon text-yellow-500"></i>';
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                themeToggle.innerHTML = '<i class="fas fa-sun text-yellow-400"></i>';
                localStorage.setItem('theme', 'dark');
            }
        }

        // Check theme preference
        function checkThemePreference() {
            if (localStorage.getItem('theme') === 'dark' || 
                (window.matchMedia('(prefers-color-scheme: dark)').matches && !localStorage.getItem('theme'))) {
                document.documentElement.classList.add('dark');
                themeToggle.innerHTML = '<i class="fas fa-sun text-yellow-400"></i>';
            } else {
                document.documentElement.classList.remove('dark');
                themeToggle.innerHTML = '<i class="fas fa-moon text-yellow-500"></i>';
            }
        }
    </script>
</body>
</html>