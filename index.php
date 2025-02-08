<?php
// config.php
define('TMDB_API_KEY', 'c65b8984909c6a8dc3c2cfde12059065');
define('YOUTUBE_API_KEY', 'AIzaSyCN0XBa0PNHhVc-MZSyykmzLC8SIiMpvPE');
define('DB_FILE', 'movies.db');

// Initialize database
try {
    $db = new SQLite3(DB_FILE);
    
    $db->exec('
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            password TEXT,
            profile_image TEXT
        );
        
        CREATE TABLE IF NOT EXISTS watchlist (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            movie_id INTEGER,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS watch_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            movie_id INTEGER,
            progress FLOAT,
            last_watched DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
    ');
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'search_suggestions':
            $query = $_GET['query'] ?? '';
            $url = "https://api.themoviedb.org/3/search/movie?api_key=" . TMDB_API_KEY . "&query=" . urlencode($query);
            echo file_get_contents($url);
            exit;
            
        case 'get_video_sources':
            $movieId = $_GET['movie_id'] ?? '';
            $videoData = getVideoSources($movieId);
            echo json_encode($videoData);
            exit;
    }
}

function getVideoSources($movieId) {
    // Get TMDB video data
    $tmdbUrl = "https://api.themoviedb.org/3/movie/{$movieId}/videos?api_key=" . TMDB_API_KEY;
    $tmdbData = json_decode(file_get_contents($tmdbUrl), true);
    
    $videos = [];
    foreach ($tmdbData['results'] as $video) {
        if ($video['site'] === 'YouTube') {
            // Get YouTube video details using YouTube Data API
            $ytUrl = "https://www.googleapis.com/youtube/v3/videos?id={$video['key']}&part=contentDetails&key=" . YOUTUBE_API_KEY;
            $ytData = json_decode(file_get_contents($ytUrl), true);
            
            $videos[] = [
                'id' => $video['key'],
                'type' => $video['type'],
                'name' => $video['name'],
                'duration' => $ytData['items'][0]['contentDetails']['duration'] ?? null
            ];
        }
    }
    return $videos;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MovieStream Pro</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0a0a0a;
            --secondary-color: #141414;
            --accent-color: #e50914;
            --text-color: #ffffff;
            --hover-color: #ff0a16;
            --player-bg: #000000;
            --overlay-bg: rgba(0, 0, 0, 0.7);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--primary-color);
            color: var(--text-color);
            line-height: 1.6;
        }

        /* Enhanced Navigation */
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            background: linear-gradient(to bottom, var(--secondary-color) 0%, transparent 100%);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            transition: background-color 0.3s;
        }

        .navbar.scrolled {
            background: var(--secondary-color);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .logo {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--accent-color);
            text-decoration: none;
            transition: transform 0.3s;
        }

        .logo:hover {
            transform: scale(1.05);
        }

        /* Enhanced Search */
        .search-container {
            position: relative;
            flex-grow: 1;
            max-width: 500px;
            margin: 0 2rem;
        }

        .search-input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.5rem;
            border-radius: 25px;
            border: 2px solid transparent;
            background-color: var(--overlay-bg);
            color: var(--text-color);
            font-size: 1rem;
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--accent-color);
            background-color: var(--secondary-color);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-color);
        }

        .search-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: var(--secondary-color);
            border-radius: 8px;
            margin-top: 0.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            display: none;
            z-index: 1000;
            max-height: 400px;
            overflow-y: auto;
        }

        .suggestion-item {
            display: flex;
            align-items: center;
            padding: 0.8rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .suggestion-item:hover {
            background-color: var(--overlay-bg);
        }

        .suggestion-poster {
            width: 40px;
            height: 60px;
            border-radius: 4px;
            margin-right: 1rem;
            object-fit: cover;
        }

        .suggestion-info h4 {
            margin: 0;
            font-size: 0.9rem;
        }

        .suggestion-info p {
            margin: 0;
            font-size: 0.8rem;
            color: #999;
        }

        /* Enhanced Video Player */
        .video-player {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: var(--player-bg);
            z-index: 2000;
            display: none;
        }

        .player-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .player-container {
            position: relative;
            width: 100%;
            max-width: 1280px;
            background-color: #000;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
        }

        .player-controls {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, var(--overlay-bg));
            padding: 1rem;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .player-wrapper:hover .player-controls {
            opacity: 1;
        }

        .controls-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .progress-bar {
            flex-grow: 1;
            height: 4px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 2px;
            cursor: pointer;
            position: relative;
        }

        .progress-fill {
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            background-color: var(--accent-color);
            border-radius: 2px;
        }

        .player-button {
            background: none;
            border: none;
            color: var(--text-color);
            cursor: pointer;
            padding: 0.5rem;
            font-size: 1.2rem;
            transition: color 0.2s;
        }

        .player-button:hover {
            color: var(--accent-color);
        }

        .volume-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .volume-slider {
            width: 80px;
            height: 4px;
            -webkit-appearance: none;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 2px;
        }

        .volume-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 12px;
            height: 12px;
            background-color: var(--accent-color);
            border-radius: 50%;
            cursor: pointer;
        }

        /* Category Tabs */
        .categories {
            margin-top: 70px;
            padding: 1rem 0;
            overflow-x: auto;
            white-space: nowrap;
            scrollbar-width: none;
            -ms-overflow-style: none;
            background: linear-gradient(var(--secondary-color), var(--primary-color));
        }

        .categories::-webkit-scrollbar {
            display: none;
        }

        .category-tab {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            margin: 0 0.5rem;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }

        .category-tab:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .category-tab.active {
            background-color: var(--accent-color);
            transform: scale(1.05);
        }

        /* Movie Grid */
        .movie-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1.5rem;
            padding: 2rem;
            margin-top: 1rem;
        }

        .movie-card {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
            background-color: var(--secondary-color);
        }

        .movie-card:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
        }

        .movie-poster {
            width: 100%;
            aspect-ratio: 2/3;
            object-fit: cover;
            transition: filter 0.3s;
        }

        .movie-card:hover .movie-poster {
            filter: brightness(0.7);
        }

        .movie-info {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 1rem;
            background: linear-gradient(transparent, rgba(0,0,0,0.9));
            transform: translateY(100%);
            transition: transform 0.3s;
        }

        .movie-card:hover .movie-info {
            transform: translateY(0);
        }

        .movie-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .movie-rating {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .rating-star {
            color: #ffd700;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .navbar {
                padding: 1rem;
            }

            .search-container {
                margin: 0 1rem;
            }

            .movie-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 1rem;
                padding: 1rem;
            }

            .player-controls {
                padding: 0.5rem;
            }

            .controls-row {
                gap: 0.5rem;
            }

            .volume-container {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <a href="#" class="logo">MovieStream</a>
        <div class="search-container">
            <i class="fas fa-search search-icon"></i>
            <input type="text" class="search-input" placeholder="Search movies...">
            <div class="search-suggestions"></div>
        </div>
        <div class="nav-links">
            <img src="profile-placeholder.jpg" alt="Profile" class="profile-img">
        </div>
    </nav>

    <!-- Categories -->
    <div class="categories">
        <div class="category-tab active">All</div>
        <div class="category-tab">Action</div>
        <div class="category-tab">Horror</div>
        <div class="category-tab">Thriller</div>
        <div class="category-tab">Drama</div>
        <div class="category-tab">Comedy</div>
        <div class="category-tab">Reality</div>
        <div class="category-tab">Cartoon</div>
        <div class="category-tab">Anime</div>
    </div>

    <!-- Movie Grid -->
    <div class="movie-grid"></div>

    <!-- Video Player -->
    <div class="video-player">
        <div class="player-wrapper">
            <div class="player-container">
                <div id="youtube-player"></div>
                <div class="player-controls">
                    <div class="progress-bar">
                    <div class="progress-fill"></div>
                    </div>
                    <div class="controls-row">
                        <button class="player-button" id="play-pause">
                            <i class="fas fa-play"></i>
                        </button>
                        <div class="volume-container">
                            <button class="player-button" id="mute">
                                <i class="fas fa-volume-up"></i>
                            </button>
                            <input type="range" class="volume-slider" min="0" max="100" value="100">
                        </div>
                        <span class="time-display">0:00 / 0:00</span>
                        <button class="player-button" id="full-screen">
                            <i class="fas fa-expand"></i>
                        </button>
                        <button class="player-button" id="close-player">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Load YouTube IFrame API -->
    <script src="https://www.youtube.com/iframe_api"></script>

    <script>
        // Global variables
        let player;
        let currentMovieData = null;
        let searchTimeout = null;
        let ytPlayer = null;

        // Initialize YouTube API
        function onYouTubeIframeAPIReady() {
            ytPlayer = new YT.Player('youtube-player', {
                height: '100%',
                width: '100%',
                playerVars: {
                    'playsinline': 1,
                    'controls': 0,
                    'disablekb': 1,
                    'rel': 0
                },
                events: {
                    'onReady': onPlayerReady,
                    'onStateChange': onPlayerStateChange
                }
            });
        }

        function onPlayerReady(event) {
            initializeCustomControls();
        }

        function onPlayerStateChange(event) {
            const playPauseBtn = document.getElementById('play-pause');
            if (event.data === YT.PlayerState.PLAYING) {
                playPauseBtn.innerHTML = '<i class="fas fa-pause"></i>';
                updateProgressBar();
            } else if (event.data === YT.PlayerState.PAUSED) {
                playPauseBtn.innerHTML = '<i class="fas fa-play"></i>';
            }
        }

        // Enhanced search with suggestions
        const searchInput = document.querySelector('.search-input');
        const suggestionsContainer = document.querySelector('.search-suggestions');

        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            const query = e.target.value.trim();

            if (query.length < 2) {
                suggestionsContainer.style.display = 'none';
                return;
            }

            searchTimeout = setTimeout(async () => {
                try {
                    const response = await fetch(`?action=search_suggestions&query=${encodeURIComponent(query)}`);
                    const data = await response.json();
                    displaySearchSuggestions(data.results.slice(0, 5));
                } catch (error) {
                    console.error('Search error:', error);
                }
            }, 300);
        });

        function displaySearchSuggestions(results) {
            suggestionsContainer.innerHTML = '';
            
            if (results.length === 0) {
                suggestionsContainer.style.display = 'none';
                return;
            }

            results.forEach(movie => {
                const item = document.createElement('div');
                item.className = 'suggestion-item';
                item.innerHTML = `
                    <img src="https://image.tmdb.org/t/p/w92${movie.poster_path}" 
                         alt="${movie.title}" 
                         class="suggestion-poster"
                         onerror="this.src='placeholder.jpg'">
                    <div class="suggestion-info">
                        <h4>${movie.title}</h4>
                        <p>${new Date(movie.release_date).getFullYear()}</p>
                    </div>
                `;
                item.addEventListener('click', () => {
                    playMovie(movie.id);
                    suggestionsContainer.style.display = 'none';
                    searchInput.value = movie.title;
                });
                suggestionsContainer.appendChild(item);
            });

            suggestionsContainer.style.display = 'block';
        }

        // Enhanced movie playback
        async function playMovie(movieId) {
            try {
                const response = await fetch(`?action=get_video_sources&movie_id=${movieId}`);
                const videoData = await response.json();

                if (videoData.length === 0) {
                    alert('No video sources found for this movie.');
                    return;
                }

                const trailer = videoData.find(v => v.type === 'Trailer') || videoData[0];
                currentMovieData = trailer;

                document.querySelector('.video-player').style.display = 'block';
                ytPlayer.loadVideoById(trailer.id);
                
                // Save to watch history
                saveWatchHistory(movieId);
            } catch (error) {
                console.error('Error playing movie:', error);
                alert('Failed to play movie. Please try again.');
            }
        }

        // Custom video player controls
        function initializeCustomControls() {
            const videoPlayer = document.querySelector('.video-player');
            const playPauseBtn = document.getElementById('play-pause');
            const muteBtn = document.getElementById('mute');
            const fullScreenBtn = document.getElementById('full-screen');
            const closeBtn = document.getElementById('close-player');
            const volumeSlider = document.querySelector('.volume-slider');
            const progressBar = document.querySelector('.progress-bar');
            const progressFill = document.querySelector('.progress-fill');
            const timeDisplay = document.querySelector('.time-display');

            playPauseBtn.addEventListener('click', () => {
                const state = ytPlayer.getPlayerState();
                if (state === YT.PlayerState.PLAYING) {
                    ytPlayer.pauseVideo();
                } else {
                    ytPlayer.playVideo();
                }
            });

            muteBtn.addEventListener('click', () => {
                if (ytPlayer.isMuted()) {
                    ytPlayer.unMute();
                    muteBtn.innerHTML = '<i class="fas fa-volume-up"></i>';
                } else {
                    ytPlayer.mute();
                    muteBtn.innerHTML = '<i class="fas fa-volume-mute"></i>';
                }
            });

            volumeSlider.addEventListener('input', (e) => {
                ytPlayer.setVolume(e.target.value);
                if (e.target.value === '0') {
                    muteBtn.innerHTML = '<i class="fas fa-volume-mute"></i>';
                } else {
                    muteBtn.innerHTML = '<i class="fas fa-volume-up"></i>';
                }
            });

            progressBar.addEventListener('click', (e) => {
                const rect = progressBar.getBoundingClientRect();
                const pos = (e.clientX - rect.left) / rect.width;
                const duration = ytPlayer.getDuration();
                ytPlayer.seekTo(duration * pos, true);
            });

            fullScreenBtn.addEventListener('click', () => {
                const container = document.querySelector('.player-container');
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                } else {
                    container.requestFullscreen();
                }
            });

            closeBtn.addEventListener('click', () => {
                ytPlayer.stopVideo();
                videoPlayer.style.display = 'none';
            });

            // Update progress bar
            setInterval(() => {
                if (ytPlayer.getPlayerState() === YT.PlayerState.PLAYING) {
                    const progress = (ytPlayer.getCurrentTime() / ytPlayer.getDuration()) * 100;
                    progressFill.style.width = `${progress}%`;
                    
                    const currentTime = formatTime(ytPlayer.getCurrentTime());
                    const duration = formatTime(ytPlayer.getDuration());
                    timeDisplay.textContent = `${currentTime} / ${duration}`;
                }
            }, 1000);
        }

        function formatTime(seconds) {
            const minutes = Math.floor(seconds / 60);
            seconds = Math.floor(seconds % 60);
            return `${minutes}:${seconds.toString().padStart(2, '0')}`;
        }

        function saveWatchHistory(movieId) {
            fetch('save_history.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    movie_id: movieId,
                    progress: 0
                })
            });
        }

        // Navbar scroll effect
        window.addEventListener('scroll', () => {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Initialize movies on load
        document.addEventListener('DOMContentLoaded', () => {
            fetchMovies();
        });
    </script>
</body>
</html>