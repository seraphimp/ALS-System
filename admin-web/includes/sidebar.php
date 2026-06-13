<?php
// sidebar.php - Admin Dashboard Sidebar Navigation

// Detect current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['REQUEST_URI'];

function isActive($page, $current) {
    return (strpos($current, $page) !== false) ? 'active' : '';
}
function isOpen($pages, $current) {
    foreach ($pages as $p) {
        if (strpos($current, $p) !== false) return 'open';
    }
    return '';
}
?>

<style>
/* ═══════════════════════════════════════
   ALS SIDEBAR — BLUE THEME
   ═══════════════════════════════════════ */

@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

.sidebar {
    width: 270px;
    background: #ffffff;
    border-right: 1.5px solid #e2e8f0;
    position: fixed;
    top: 0; left: 0; bottom: 0;
    height: 100vh;
    overflow-y: auto;
    overflow-x: hidden;
    transition: transform 0.3s cubic-bezier(0.4,0,0.2,1);
    z-index: 1000;
    display: flex;
    flex-direction: column;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

/* Subtle scrollbar */
.sidebar::-webkit-scrollbar { width: 3px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }
.sidebar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 3px; }

/* ── BRAND ── */
.sidebar-brand {
    padding: 20px 18px 16px;
    border-bottom: 1.5px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 11px;
    flex-shrink: 0;
    text-decoration: none;
}

.sidebar-brand .brand-logo {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, #1d4ed8, #3b82f6);
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(59,130,246,0.35);
    flex-shrink: 0;
    overflow: hidden;
}

.sidebar-brand .brand-logo img {
    width: 100%; height: 100%;
    object-fit: contain;
    filter: brightness(0) invert(1);
    padding: 6px;
}

/* Fallback icon if image fails */
.sidebar-brand .brand-logo .brand-icon-fallback {
    color: white;
    font-size: 1.1rem;
}

.sidebar-brand .brand-text h2 {
    font-size: 0.82rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.35;
    letter-spacing: -0.01em;
}

.sidebar-brand .brand-text span {
    font-size: 0.68rem;
    color: #94a3b8;
    font-weight: 500;
    letter-spacing: 0.2px;
}

/* ── NAV CONTAINER ── */
.sidebar-nav {
    padding: 14px 10px;
    flex: 1;
}

/* ── NAV SECTIONS ── */
.sb-section {
    margin-bottom: 4px;
}

.sb-section-label {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 10px 5px;
    font-size: 0.64rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #94a3b8;
}

.sb-section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #f1f5f9;
    margin-left: 4px;
}

/* ── NAV LIST ── */
.sb-list {
    list-style: none;
    margin: 0; padding: 0;
}

.sb-list > li {
    margin-bottom: 1px;
}

/* ── NAV ITEM ── */
.sb-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border-radius: 9px;
    color: #475569;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.18s ease;
    position: relative;
    cursor: pointer;
    user-select: none;
}

.sb-item .sb-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.82rem;
    color: #64748b;
    flex-shrink: 0;
    transition: all 0.18s ease;
}

.sb-item .sb-label {
    flex: 1;
    line-height: 1;
}

.sb-item .sb-chevron {
    font-size: 0.6rem;
    color: #94a3b8;
    transition: transform 0.2s ease;
    flex-shrink: 0;
}

/* Hover state */
.sb-item:hover {
    background: #eff6ff;
    color: #1d4ed8;
}
.sb-item:hover .sb-icon {
    background: #dbeafe;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

/* Active state */
.sb-list > li.active > .sb-item,
.sb-list > li.active > a.sb-item {
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 600;
}
.sb-list > li.active > .sb-item .sb-icon,
.sb-list > li.active > a.sb-item .sb-icon {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: white;
    box-shadow: 0 3px 8px rgba(29,78,216,0.3);
}
.sb-list > li.active > .sb-item::after,
.sb-list > li.active > a.sb-item::after {
    content: '';
    position: absolute;
    right: 0; top: 20%; bottom: 20%;
    width: 3px;
    background: #1d4ed8;
    border-radius: 3px 0 0 3px;
}

/* ── DROPDOWN ── */
.sb-dropdown > .sb-item .sb-chevron {
    display: flex;
}

.sb-dropdown.open > .sb-item .sb-chevron {
    transform: rotate(180deg);
}

.sb-dropdown.open > .sb-item {
    background: #eff6ff;
    color: #1d4ed8;
}
.sb-dropdown.open > .sb-item .sb-icon {
    background: #dbeafe;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

/* Dropdown children */
.sb-sub {
    list-style: none;
    margin: 3px 0 4px 16px;
    padding: 0;
    border-left: 2px solid #e2e8f0;
    padding-left: 8px;
    display: none;
}

.sb-dropdown.open > .sb-sub {
    display: block;
}

.sb-sub > li > a {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    border-radius: 7px;
    color: #64748b;
    text-decoration: none;
    font-size: 0.83rem;
    font-weight: 500;
    transition: all 0.15s ease;
}

.sb-sub > li > a::before {
    content: '';
    width: 5px; height: 5px;
    border-radius: 50%;
    background: #cbd5e1;
    flex-shrink: 0;
    transition: all 0.15s ease;
}

.sb-sub > li > a:hover {
    color: #1d4ed8;
    background: #eff6ff;
    padding-left: 14px;
}
.sb-sub > li > a:hover::before {
    background: #1d4ed8;
    box-shadow: 0 0 0 3px #dbeafe;
}

.sb-sub > li.active > a {
    color: #1d4ed8;
    font-weight: 600;
}
.sb-sub > li.active > a::before {
    background: #1d4ed8;
}

/* ── DIVIDER ── */
.sb-divider {
    height: 1px;
    background: #f1f5f9;
    margin: 8px 10px;
}

/* ── FOOTER (Logout) ── */
.sidebar-footer {
    padding: 10px 10px 14px;
    border-top: 1.5px solid #f1f5f9;
    flex-shrink: 0;
}

.sb-logout {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border-radius: 9px;
    color: #64748b;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.18s ease;
    cursor: pointer;
}

.sb-logout .sb-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.82rem;
    color: #64748b;
    flex-shrink: 0;
    transition: all 0.18s ease;
}

.sb-logout:hover {
    background: #fff1f2;
    color: #e11d48;
}
.sb-logout:hover .sb-icon {
    background: #ffe4e6;
    border-color: #fecdd3;
    color: #e11d48;
}

/* ── USER PROFILE STRIP ── */
.sb-user-strip {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 12px;
    margin: 0 10px 8px;
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    border-radius: 10px;
    border: 1px solid #bfdbfe;
}

.sb-user-avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1d4ed8, #3b82f6);
    display: flex; align-items: center; justify-content: center;
    color: white;
    font-size: 0.78rem;
    font-weight: 700;
    flex-shrink: 0;
}

.sb-user-info .sb-user-name {
    font-size: 0.8rem;
    font-weight: 700;
    color: #1e3a8a;
    line-height: 1.2;
}
.sb-user-info .sb-user-role {
    font-size: 0.68rem;
    color: #3b82f6;
    font-weight: 500;
}

/* ── BADGE (notification) ── */
.sb-badge {
    margin-left: auto;
    background: #1d4ed8;
    color: white;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 40px;
    min-width: 18px;
    text-align: center;
}

/* ── RESPONSIVE ── */
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
}
</style>

<aside class="sidebar" id="mainSidebar">

    <!-- Brand -->
    <a href="dashboard.php" class="sidebar-brand">
        <div class="brand-logo">
            <img src="/logo"
                 alt="ALS"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <span class="brand-icon-fallback" style="display:none;">
                <i class="fas fa-graduation-cap"></i>
            </span>
        </div>
        <div class="brand-text">
            <h2>Alternative Learning System</h2>
            <span>Admin Portal</span>
        </div>
    </a>

    <!-- Nav -->
    <nav class="sidebar-nav">

        <!-- Main -->
        <div class="sb-section">
            <ul class="sb-list">
                <li class="<?php echo ('dashboard.php') ? 'active' : ''; ?>">
                    <a href="/AdminDashboard" class="sb-item">
                        <span class="sb-icon"><i class="fas fa-home"></i></span>
                        <span class="sb-label">Dashboard</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Student Management -->
        <div class="sb-section">
            <div class="sb-section-label">Student Management</div>
            <ul class="sb-list">
                <li class="<?php echo isActive('/AllStudents', $current_path) ? 'active' : ''; ?>">
                    <a href="/AllStudents" class="sb-item">
                        <span class="sb-icon"><i class="fas fa-user-graduate"></i></span>
                        <span class="sb-label">All Students</span>
                    </a>
                </li>
                <li class="<?php echo isActive('/AddStudent', $current_path) ? 'active' : ''; ?>">
                    <a href="/AddStudents" class="sb-item">
                        <span class="sb-icon"><i class="fas fa-user-plus"></i></span>
                        <span class="sb-label">Add Student</span>
                    </a>
                </li>

                <!-- Enrollments dropdown -->
                <li class="sb-dropdown <?php echo isOpen(['enrollments/'], $current_path); ?>">
                    <div class="sb-item" data-toggle="dropdown">
                        <span class="sb-icon"><i class="fas fa-clipboard-list"></i></span>
                        <span class="sb-label">Enrollments</span>
                        <i class="fas fa-chevron-down sb-chevron"></i>
                    </div>
                    <ul class="sb-sub">
                        <li class="<?php echo isActive('/AdminSummary', $current_path); ?>">
                            <a href="/AdminSummary">Summary</a>
                        </li>
                        <li class="<?php echo isActive('/AdminReports', $current_path); ?>">
                            <a href="/AdminReports">Reports</a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>

        <!-- Teacher Management -->
        <div class="sb-section">
            <div class="sb-section-label">Teacher Management</div>
            <ul class="sb-list">
                <li class="<?php echo isActive('/AdminAllTeachers', $current_path); ?>">
                    <a href="/AdminAllTeachers" class="sb-item">
                        <span class="sb-icon"><i class="fas fa-chalkboard-teacher"></i></span>
                        <span class="sb-label">All Teachers</span>
                    </a>
                </li>
                <li class="<?php echo isActive('/AdminAddteachers', $current_path); ?>">
                    <a href="/AdminAddteachers" class="sb-item">
                        <span class="sb-icon"><i class="fas fa-user-tie"></i></span>
                        <span class="sb-label">Add Teacher</span>
                    </a>
                </li>

                <!-- Assignment dropdown -->
                <li class="sb-dropdown <?php echo isOpen(['assignments/'], $current_path); ?>">
                    <div class="sb-item" data-toggle="dropdown">
                        <span class="sb-icon"><i class="fas fa-tasks"></i></span>
                        <span class="sb-label">Assignments</span>
                        <i class="fas fa-chevron-down sb-chevron"></i>
                    </div>
                    <ul class="sb-sub">
                        <li class="<?php echo isActive('/AdminTeacherMonitor', $current_path); ?>">
                            <a href="/AdminTeacherMonitor">Teacher Monitoring</a>
                        </li>
                        <li class="<?php echo isActive('/AdminTeacherReports', $current_path); ?>">
                            <a href="/AdminTeacherReports">Reports</a>
                        </li>
                       <!---- <li class="<?php echo isActive('assignments/announcement', $current_path); ?>">
                            <a href="assignments/announcement.php">Announcement</a>
                        </li>--->
                    </ul>
                </li>
            </ul>
        </div>
        
        <!-- ========== NEW: ASSESSMENT MANAGEMENT ========== -->
        <div class="sb-section">
            <div class="sb-section-label">Assessment Management</div>
            <ul class="sb-list">
                <!-- Pre/Post Tests dropdown -->
                <li class="sb-dropdown <?php echo isOpen(['pre-post-tests'], $current_path); ?>">
                    <div class="sb-item" data-toggle="dropdown">
                        <span class="sb-icon"><i class="fas fa-flag-checkered"></i></span>
                        <span class="sb-label">Pre/Post Tests</span>
                        <i class="fas fa-chevron-down sb-chevron"></i>
                    </div>
                    <ul class="sb-sub">
                        <li class="<?php echo isActive('/AdminManages', $current_path); ?>">
                            <a href="/AdminManages">Manage Tests</a>
                        </li>
                        <li class="<?php echo isActive('/AdminCreates', $current_path); ?>">
                            <a href="/AdminCreates">Create New</a>
                        </li>
                        <li class="<?php echo isActive('/AdminQuestions', $current_path); ?>">
                            <a href="/AdminQuestions">Question Bank</a>
                        </li>
                    </ul>
                </li>
                
                <!-- Summative Tests dropdown 
                <li class="sb-dropdown <?php echo isOpen(['summative-tests'], $current_path); ?>">
                    <div class="sb-item" data-toggle="dropdown">
                        <span class="sb-icon"><i class="fas fa-file-signature"></i></span>
                        <span class="sb-label">Summative Tests</span>
                        <i class="fas fa-chevron-down sb-chevron"></i>
                    </div>
                    <ul class="sb-sub">
                        <li class="<?php echo isActive('summative-tests/manage', $current_path); ?>">
                            <a href="summative-tests/manage.php">Manage Tests</a>
                        </li>
                        <li class="<?php echo isActive('summative-tests/create', $current_path); ?>">
                            <a href="summative-tests/create.php">Create New</a>
                        </li>
                        <li class="<?php echo isActive('summative-tests/questions', $current_path); ?>">
                            <a href="summative-tests/questions.php">Question Bank</a>
                        </li>
                    </ul>
                </li>-->
                
                <!-- Distribution -->
                <li class="<?php echo isActive('assessment-distribution', $current_path); ?>">
                    <a href="/AdminDistributes" class="sb-item">
                        <span class="sb-icon"><i class="fas fa-share-alt"></i></span>
                        <span class="sb-label">Distribution</span>
                        <span class="sb-badge">NEW</span>
                    </a>
                </li>
                
                <!-- Reports -->
                <li class="<?php echo isActive('assessment-reports', $current_path); ?>">
                    <a href="/assessmentreports" class="sb-item">
                        <span class="sb-icon"><i class="fas fa-chart-line"></i></span>
                        <span class="sb-label">Reports</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- System -->
        <div class="sb-section">
            <div class="sb-section-label">System</div>
            <ul class="sb-list">
                <li class="<?php echo isActive('barangays', $current_path); ?>">
                    <a href="/AdminBarangays" class="sb-item">
                        <span class="sb-icon"><i class="fas fa-map-marker-alt"></i></span>
                        <span class="sb-label">Barangays</span>
                    </a>
                </li>
                <li class="<?php echo isActive('settings', $current_path); ?>">
                    <a href="/AdminSettings" class="sb-item">
                        <span class="sb-icon"><i class="fas fa-cog"></i></span>
                        <span class="sb-label">Settings</span>
                    </a>
                </li>
            </ul>
        </div>

    </nav>

    <!-- Footer: User strip + Logout -->
    <div class="sidebar-footer">
        <?php if(isset($admin) && !empty($admin['full_name'])): ?>
        <div class="sb-user-strip">
            <div class="sb-user-avatar">
                <?php echo strtoupper(substr($admin['full_name'], 0, 1)); ?>
            </div>
            <div class="sb-user-info">
                <div class="sb-user-name"><?php echo htmlspecialchars($admin['full_name']); ?></div>
                <div class="sb-user-role">Administrator</div>
            </div>
        </div>
        <?php endif; ?>

        <a href="/AdminLogout" class="sb-logout">
            <span class="sb-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span>Logout</span>
        </a>
    </div>
</aside>

<script>
(function() {
    'use strict';
    
    // Wait for DOM to be fully loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
    
    function initSidebar() {
        // Dropdown toggle functionality
        setupDropdowns();
        
        // Set active states based on current URL
        setActiveStates();
        
        // Auto-open dropdown if child is active
        openDropdownForActiveChild();
    }
    
    function setupDropdowns() {
        // Get all dropdown toggle elements
        const dropdownToggles = document.querySelectorAll('.sb-dropdown > .sb-item[data-toggle="dropdown"], .sb-dropdown > .sb-item');
        
        dropdownToggles.forEach(function(toggle) {
            // Remove any existing listeners to prevent duplicates
            toggle.removeEventListener('click', handleDropdownClick);
            toggle.addEventListener('click', handleDropdownClick);
        });
    }
    
    function handleDropdownClick(e) {
        e.preventDefault();
        e.stopPropagation(); // Prevent event bubbling
        
        const parent = this.closest('.sb-dropdown');
        if (!parent) return;
        
        const isOpen = parent.classList.contains('open');
        
        // Optional: Close other dropdowns (comment out if you want multiple open)
        closeOtherDropdowns(parent);
        
        // Toggle current dropdown
        if (isOpen) {
            parent.classList.remove('open');
        } else {
            parent.classList.add('open');
        }
    }
    
    function closeOtherDropdowns(currentDropdown) {
        document.querySelectorAll('.sb-dropdown.open').forEach(function(dropdown) {
            if (dropdown !== currentDropdown) {
                dropdown.classList.remove('open');
            }
        });
    }
    
    function setActiveStates() {
        const currentPath = window.location.pathname + window.location.search;
        const currentFile = currentPath.split('/').pop().split('?')[0]; // Get filename without query params
        
        // Check all navigation links
        document.querySelectorAll('.sb-list a[href]').forEach(function(link) {
            const href = link.getAttribute('href');
            if (!href) return;
            
            // Get filename from href
            const hrefFile = href.split('/').pop().split('?')[0];
            
            // Check if current page matches link
            if (currentFile === hrefFile || currentPath.includes(href.replace('.php', ''))) {
                // Mark the parent li as active
                const li = link.closest('li');
                if (li) {
                    li.classList.add('active');
                    
                    // If this is in a dropdown, open the dropdown
                    const dropdown = li.closest('.sb-dropdown');
                    if (dropdown) {
                        dropdown.classList.add('open');
                    }
                }
            }
        });
    }
    
    function openDropdownForActiveChild() {
        document.querySelectorAll('.sb-dropdown').forEach(function(dropdown) {
            if (dropdown.querySelector('li.active')) {
                dropdown.classList.add('open');
            }
        });
    }
    
    // Optional: Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.sb-dropdown')) {
            // Comment out the next line if you want dropdowns to stay open when clicking outside
            // closeAllDropdowns();
        }
    });
    
    function closeAllDropdowns() {
        document.querySelectorAll('.sb-dropdown.open').forEach(function(dropdown) {
            dropdown.classList.remove('open');
        });
    }
})();
</script>

<!-- Add Font Awesome if not already included -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">