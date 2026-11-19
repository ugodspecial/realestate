<?php
/** No-code administration. Every action enforces RBAC, CSRF on POST, validation, and auditing. */
final class AdminController {
    public static function dispatch(string $route, string $method, array $user): void {
        $path = trim(substr($route, 5), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $resource = $segments[0] ?? '';
        try {
            match (true) {
                $resource === '' || $resource === 'dashboard' => self::dashboard($user),
                $resource === 'properties' => self::properties($segments, $method, $user),
                $resource === 'images' => self::imageAction($segments, $method, $user),
                $resource === 'enquiries' => self::enquiries($segments, $method, $user),
                $resource === 'categories' => self::taxonomy('categories', $segments, $method, $user),
                $resource === 'locations' => self::taxonomy('locations', $segments, $method, $user),
                $resource === 'testimonials' => self::testimonials($segments, $method, $user),
                $resource === 'users' => self::users($segments, $method, $user),
                $resource === 'settings' => self::settings($method, $user),
                $resource === 'audit' => self::audit($user),
                $resource === 'roadmap' => self::roadmap($user),
                $resource === 'account' => self::account($method, $user),
                default => abort_page(404, 'We couldn’t find that admin page.'),
            };
        } catch (PDOException $e) {
            error_log('Admin: ' . $e->getMessage());
            abort_page(500, 'Something went wrong while saving. Your changes were not lost — please try again.');
        }
    }

    private static function layout(string $template, string $title, array $data, array $user, string $active): void {
        $data += ['admin_title' => $title, 'admin_user' => $user, 'admin_active' => $active];
        render('admin/' . $template, $data, 'admin/layout');
    }

    private static function dashboard(array $user): void {
        // One dashboard serves four roles, but not with one set of numbers.
        // Every panel, counter, and link below is gated by the permission for
        // the thing it shows. A role must never be shown a figure it cannot
        // open, and a link that answers 403 is a bug, not a teaser.
        $canListings = Auth::can('properties.manage');
        $canApprove = Auth::can('properties.approve');
        $canEnquiries = Auth::can('enquiries.manage');
        $canContent = Auth::can('content.manage');
        $canUsers = Auth::can('users.manage');
        $canAudit = Auth::can('audit.view');

        $stats = [];
        $recentProperties = [];
        $recentEnquiries = [];
        $recentAudit = [];
        $contentSummary = [];
        $myEnquiries = [];

        if ($canListings) {
            $stats['published'] = (int)Database::value("SELECT COUNT(*) FROM properties WHERE publish_status='published'");
            $stats['pending'] = (int)Database::value("SELECT COUNT(*) FROM properties WHERE publish_status='pending'");
            $stats['available'] = (int)Database::value("SELECT COUNT(*) FROM properties WHERE publish_status='published' AND status='available'");
            $recentProperties = Database::all(Properties::SELECT . ' ORDER BY p.updated_at DESC LIMIT 5');
        }
        if ($canEnquiries) {
            $stats['enquiries'] = (int)Database::value("SELECT COUNT(*) FROM enquiries WHERE status='new'");
            $recentEnquiries = Database::all('SELECT e.*, p.title AS property_title FROM enquiries e LEFT JOIN properties p ON p.id=e.property_id ORDER BY e.created_at DESC LIMIT 5');
        }
        if ($canContent) {
            // A content editor lands here and needs to see the work that is
            // actually theirs. Rows are links into the pages they can open.
            $contentSummary = [
                'categories' => (int)Database::value('SELECT COUNT(*) FROM categories'),
                'locations' => (int)Database::value('SELECT COUNT(*) FROM locations'),
                'testimonials' => (int)Database::value('SELECT COUNT(*) FROM testimonials'),
                'testimonials_pending' => (int)Database::value('SELECT COUNT(*) FROM testimonials WHERE approved=0'),
            ];
        }
        if ($canUsers) {
            $stats['staff'] = (int)Database::value('SELECT COUNT(*) FROM users WHERE active=1');
            $stats['roles'] = count(array_unique(array_column(Database::all('SELECT role FROM users WHERE active=1'), 'role')));
        }
        if ($canAudit) {
            $recentAudit = Database::all('SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 6');
        }

        self::layout('dashboard', 'Dashboard', compact(
            'stats', 'recentProperties', 'recentEnquiries', 'recentAudit', 'contentSummary',
            'canListings', 'canApprove', 'canEnquiries', 'canContent', 'canUsers', 'canAudit'
        ), $user, 'dashboard');
    }

    /* ---------------- Properties ---------------- */
    private static function properties(array $segments, string $method, array $user): void {
        Auth::require('properties.manage');
        $action = $segments[1] ?? 'index';
        if ($action === 'index' && $method === 'GET') { self::propertyIndex($user); return; }
        if ($action === 'new') {
            if ($method === 'POST') self::propertyStore($user); else self::propertyForm(null, $user);
            return;
        }
        if (ctype_digit($action)) {
            $property = Properties::byId((int)$action);
            if (!$property) abort_page(404, 'That property could not be found.');
            $sub = $segments[2] ?? 'edit';
            if ($sub === 'edit' && $method === 'GET') { self::propertyForm($property, $user); return; }
            if ($sub === 'edit' && $method === 'POST') { self::propertyUpdate($property, $user); return; }
            if ($sub === 'approve' && $method === 'POST') { self::propertyApprove($property, $user); return; }
            if ($sub === 'delete' && $method === 'POST') { self::propertyDelete($property, $user); return; }
            if ($sub === 'images') { self::propertyImages($property, $method, $user); return; }
        }
        abort_page(404, 'We couldn’t find that property page.');
    }

    private static function propertyIndex(array $user): void {
        $f = ['q' => query_value('q'), 'deal' => query_value('deal'), 'status' => query_value('status'), 'publish_status' => query_value('publish_status'), 'category' => query_value('category'), 'location' => query_value('location'), 'sort' => query_value('sort', 'newest')];
        $f = array_filter($f, fn($v) => $v !== '');
        $page = max(1, (int)query_value('page', '1'));
        $result = Properties::search($f, $page, 12, true);
        self::layout('properties/index', 'Properties', [
            'items' => $result['items'], 'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages'],
            'filters' => $f, 'categories' => Database::all('SELECT * FROM categories ORDER BY name'), 'locations' => Database::all('SELECT * FROM locations ORDER BY name'),
        ], $user, 'properties');
    }

    private static function propertyForm(?array $property, array $user): void {
        self::layout('properties/form', $property ? 'Edit property' : 'Add property', [
            'property' => $property, 'categories' => Database::all('SELECT * FROM categories ORDER BY name'), 'locations' => Database::all('SELECT * FROM locations ORDER BY name'),
        ], $user, 'properties');
    }

    private static function propertyInput(?array $property = null): array {
        return [
            'title' => mb_substr(input('title'), 0, 180),
            'price' => max(0, (float)preg_replace('/[^0-9.]/', '', input('price'))),
            'category_id' => (int)input('category_id'), 'location_id' => (int)input('location_id'),
            'deal' => input('deal') === 'rent' ? 'rent' : 'sale',
            'status' => in_array(input('status'), ['available', 'sold', 'rented'], true) ? input('status') : 'available',
            'featured' => !empty($_POST['featured']) ? 1 : 0,
            'beds' => max(0, (int)input('beds')), 'baths' => max(0, (int)input('baths')), 'parking' => max(0, (int)input('parking')),
            'area_sqm' => max(0, (float)input('area_sqm')),
            'address' => mb_substr(input('address'), 0, 255),
            'description' => mb_substr(input('description'), 0, 20000),
            'amenities' => json_encode(array_slice(array_map(fn($a) => mb_substr($a, 0, 80), amenities_list((string)input('amenities'))), 0, 40)),
            'seo_title' => mb_substr(input('seo_title'), 0, 180), 'seo_description' => mb_substr(input('seo_description'), 0, 320),
        ];
    }

    private static function nextListingId(): string {
        $year = date('Y');
        $n = (int)Database::value("SELECT COUNT(*) FROM properties WHERE listing_id LIKE ?", ["CT-$year-%"]) + 1;
        do { $id = sprintf('CT-%s-%04d', $year, $n++); } while (Database::one('SELECT id FROM properties WHERE listing_id=?', [$id]));
        return $id;
    }

    private static function propertyStore(array $user): void {
        verify_csrf();
        $data = self::propertyInput();
        $errors = Properties::validate($data);
        if ($errors) { remember_old($_POST); foreach ($errors as $e) flash('error', $e); redirect('admin/properties/new'); }
        $publish = Auth::can('properties.approve') && !empty($_POST['publish']) ? 'published' : 'pending';
        $id = Database::insert('properties', $data + [
            'listing_id' => self::nextListingId(), 'slug' => Properties::slug($data['title']),
            'publish_status' => $publish, 'created_by' => $user['id'], 'approved_by' => $publish === 'published' ? $user['id'] : null,
            'created_at' => Database::now(), 'updated_at' => Database::now(),
        ]);
        Audit::log('property.create', 'property', $id, "Created “{$data['title']}” ($publish)");
        clear_old();
        flash('success', $publish === 'published' ? 'Property published. Now add its photographs.' : 'Property saved and sent for approval. Now add its photographs.');
        redirect('admin/properties/' . $id . '/images');
    }

    private static function propertyUpdate(array $property, array $user): void {
        verify_csrf();
        $data = self::propertyInput($property);
        $errors = Properties::validate($data);
        if ($errors) { remember_old($_POST); foreach ($errors as $e) flash('error', $e); redirect('admin/properties/' . $property['id'] . '/edit'); }
        $data['slug'] = Properties::slug($data['title'], (int)$property['id']);
        $data['updated_at'] = Database::now();
        // Publication is an approver's decision, and editing does not transfer
        // it. A manager changing a live listing must not be able to alter what
        // the public sees without it going back through approval, so any edit
        // to published content by a non-approver returns it to the queue.
        $wasPublished = $property['publish_status'] === 'published';
        $canApprove = Auth::can('properties.approve');
        if (!$canApprove && !$wasPublished) {
            $data['publish_status'] = 'pending';
        } elseif (!$canApprove && $wasPublished) {
            $data['publish_status'] = 'pending';
            $data['approved_by'] = null;
        } elseif (!empty($_POST['publish'])) {
            $data['publish_status'] = 'published';
            $data['approved_by'] = $user['id'];
        } elseif (isset($_POST['unpublish'])) {
            $data['publish_status'] = 'draft';
            $data['approved_by'] = null;
        }
        Database::update('properties', $data, (int)$property['id']);
        $returnedToQueue = $wasPublished && !$canApprove && $data['publish_status'] === 'pending';
        Audit::log(
            'property.update',
            'property',
            (int)$property['id'],
            "Updated “{$data['title']}”" . ($returnedToQueue ? ' — returned to the approval queue' : '')
        );
        clear_old();
        flash('success', $returnedToQueue
            ? 'Property saved. Because you changed a live listing and cannot approve it yourself, it is back in the approval queue until an approver reviews it.'
            : 'Property saved.');
        redirect('admin/properties/' . $property['id'] . '/edit');
    }

    private static function propertyApprove(array $property, array $user): void {
        Auth::require('properties.approve');
        verify_csrf();
        $decision = input('decision');
        $status = $decision === 'reject' ? 'draft' : 'published';
        Database::update('properties', ['publish_status' => $status, 'approved_by' => $status === 'published' ? $user['id'] : null, 'updated_at' => Database::now()], (int)$property['id']);
        Audit::log('property.' . ($status === 'published' ? 'approve' : 'reject'), 'property', (int)$property['id'], "“{$property['title']}” → $status");
        flash('success', $status === 'published' ? 'Property approved and published.' : 'Property returned to drafts.');
        redirect(query_value('redirect') === 'pending' ? 'admin/properties?publish_status=pending' : 'admin/properties');
    }

    private static function propertyDelete(array $property, array $user): void {
        verify_csrf();
        foreach (Properties::images((int)$property['id']) as $image) Uploads::delete($image);
        Database::query('DELETE FROM properties WHERE id=?', [$property['id']]);
        Audit::log('property.delete', 'property', (int)$property['id'], "Deleted “{$property['title']}” ({$property['listing_id']})");
        flash('success', 'Property and its images were deleted.');
        redirect('admin/properties');
    }

    private static function propertyImages(array $property, string $method, array $user): void {
        if ($method === 'POST') {
            verify_csrf();
            $errors = Uploads::store($_FILES['images'] ?? [], (int)$property['id'], $property['title']);
            if ($errors) { foreach ($errors as $e) flash('error', $e); }
            else { Audit::log('property.images', 'property', (int)$property['id'], "Uploaded photographs for “{$property['title']}”"); flash('success', 'Photographs uploaded.'); }
            redirect('admin/properties/' . $property['id'] . '/images');
        }
        self::layout('properties/images', 'Property photographs', ['property' => $property, 'images' => Properties::images((int)$property['id'])], $user, 'properties');
    }

    private static function imageAction(array $segments, string $method, array $user): void {
        Auth::require('properties.manage');
        if ($method !== 'POST' || empty($segments[1]) || !ctype_digit($segments[1])) abort_page(404, 'We couldn’t find that image.');
        verify_csrf();
        $image = Database::one('SELECT * FROM property_images WHERE id=?', [(int)$segments[1]]);
        if (!$image) abort_page(404, 'That image could not be found.');
        $action = $segments[2] ?? '';
        if ($action === 'cover') {
            Database::query('UPDATE property_images SET is_cover=0 WHERE property_id=?', [$image['property_id']]);
            Database::update('property_images', ['is_cover' => 1], (int)$image['id']);
            Audit::log('image.cover', 'property', (int)$image['property_id'], 'Changed cover photograph');
            flash('success', 'Cover photograph updated.');
        } elseif ($action === 'caption') {
            Database::update('property_images', ['alt' => mb_substr(input('alt'), 0, 255)], (int)$image['id']);
            flash('success', 'Photo caption saved.');
        } elseif ($action === 'delete') {
            Uploads::delete($image);
            Audit::log('image.delete', 'property', (int)$image['property_id'], 'Deleted a photograph');
            flash('success', 'Photograph deleted.');
        } else abort_page(404, 'We couldn’t find that image action.');
        redirect('admin/properties/' . $image['property_id'] . '/images');
    }

    /* ---------------- Enquiries ---------------- */
    private static function enquiries(array $segments, string $method, array $user): void {
        Auth::require('enquiries.manage');
        $action = $segments[1] ?? 'index';
        if ($action === 'index' && $method === 'GET') {
            $status = query_value('status');
            $where = $status !== '' ? 'WHERE e.status=?' : '';
            $params = $status !== '' ? [$status] : [];
            $items = Database::all("SELECT e.*, p.title AS property_title, p.slug AS property_slug FROM enquiries e LEFT JOIN properties p ON p.id=e.property_id $where ORDER BY e.created_at DESC LIMIT 200", $params);
            $counts = array_column(Database::all('SELECT status, COUNT(*) c FROM enquiries GROUP BY status'), 'c', 'status');
            self::layout('enquiries/index', 'Enquiries', ['items' => $items, 'status' => $status, 'counts' => $counts], $user, 'enquiries');
            return;
        }
        if (ctype_digit($action)) {
            $enquiry = Database::one('SELECT e.*, p.title AS property_title, p.slug AS property_slug, p.listing_id FROM enquiries e LEFT JOIN properties p ON p.id=e.property_id WHERE e.id=?', [(int)$action]);
            if (!$enquiry) abort_page(404, 'That enquiry could not be found.');
            if ($method === 'POST') {
                verify_csrf();
                $status = in_array(input('status'), ['new', 'contacted', 'closed'], true) ? input('status') : $enquiry['status'];
                Database::update('enquiries', ['status' => $status, 'admin_notes' => mb_substr(input('admin_notes'), 0, 5000), 'updated_at' => Database::now()], (int)$enquiry['id']);
                Audit::log('enquiry.update', 'enquiry', (int)$enquiry['id'], "“{$enquiry['reference']}” → $status");
                flash('success', 'Enquiry updated.');
                redirect('admin/enquiries/' . $enquiry['id']);
            }
            self::layout('enquiries/show', 'Enquiry ' . $enquiry['reference'], ['enquiry' => $enquiry], $user, 'enquiries');
            return;
        }
        abort_page(404, 'We couldn’t find that enquiry page.');
    }

    /* ---------------- Categories & locations ---------------- */
    private static function taxonomy(string $table, array $segments, string $method, array $user): void {
        Auth::require('content.manage');
        $label = $table === 'categories' ? 'Category' : 'Location';
        $active = $table;
        $action = $segments[1] ?? 'index';
        if ($action === 'index' && $method === 'GET') {
            $countCol = $table === 'categories' ? 'category_id' : 'location_id';
            $items = Database::all("SELECT t.*, (SELECT COUNT(*) FROM properties p WHERE p.$countCol=t.id AND p.publish_status='published') AS live_count FROM $table t ORDER BY t.name");
            self::layout('taxonomy/index', $label === 'Category' ? 'Categories' : 'Locations', ['items' => $items, 'table' => $table, 'label' => $label], $user, $active);
            return;
        }
        if ($action === 'save' && $method === 'POST') {
            verify_csrf();
            $id = (int)input('id');
            $name = mb_substr(input('name'), 0, $table === 'categories' ? 80 : 100);
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(input('slug') ?: $name)), '-') ?: 'item';
            $description = mb_substr(input('description'), 0, 2000);
            $errors = [];
            if (mb_strlen($name) < 2) $errors[] = "Enter a $label name.";
            $exists = Database::one("SELECT id FROM $table WHERE slug=?" . ($id ? ' AND id<>?' : ''), $id ? [$slug, $id] : [$slug]);
            if ($exists) $errors[] = 'That URL slug is already in use. Choose another.';
            if ($errors) { foreach ($errors as $e) flash('error', $e); redirect('admin/' . $table); }
            $data = ['name' => $name, 'slug' => $slug, 'description' => $description];
            if ($table === 'categories') $data['icon'] = preg_match('/^[a-z]+$/', input('icon')) ? input('icon') : 'home';
            else $data['city'] = mb_substr(input('city') ?: $name, 0, 80);
            if ($id) { Database::update($table, $data, $id); Audit::log('taxonomy.update', $table, $id, "Updated $label “{$name}”"); flash('success', "$label updated."); }
            else { $id = Database::insert($table, $data); Audit::log('taxonomy.create', $table, $id, "Created $label “{$name}”"); flash('success', "$label created."); }
            redirect('admin/' . $table);
        }
        if ($action === 'delete' && $method === 'POST' && ctype_digit($segments[2] ?? '')) {
            verify_csrf();
            $id = (int)$segments[2];
            $countCol = $table === 'categories' ? 'category_id' : 'location_id';
            if ((int)Database::value("SELECT COUNT(*) FROM properties WHERE $countCol=?", [$id])) { flash('error', "This $label is used by properties and cannot be deleted. Reassign them first."); redirect('admin/' . $table); }
            $row = Database::one("SELECT name FROM $table WHERE id=?", [$id]);
            Database::query("DELETE FROM $table WHERE id=?", [$id]);
            Audit::log('taxonomy.delete', $table, $id, 'Deleted ' . ($row['name'] ?? 'item'));
            flash('success', "$label deleted.");
            redirect('admin/' . $table);
        }
        abort_page(404, 'We couldn’t find that page.');
    }

    /* ---------------- Testimonials ---------------- */
    private static function testimonials(array $segments, string $method, array $user): void {
        Auth::require('content.manage');
        $action = $segments[1] ?? 'index';
        if ($action === 'index' && $method === 'GET') {
            self::layout('testimonials/index', 'Testimonials', ['items' => Database::all('SELECT * FROM testimonials ORDER BY approved ASC, id DESC')], $user, 'testimonials');
            return;
        }
        if ($action === 'save' && $method === 'POST') {
            verify_csrf();
            $id = (int)input('id');
            $data = ['name' => mb_substr(input('name'), 0, 120), 'location' => mb_substr(input('location'), 0, 120), 'quote' => mb_substr(input('quote'), 0, 2000), 'rating' => min(5, max(1, (int)input('rating', '5'))), 'approved' => !empty($_POST['approved']) ? 1 : 0];
            if (mb_strlen($data['name']) < 2 || mb_strlen($data['quote']) < 10) { flash('error', 'Enter a name and a testimonial of at least 10 characters.'); redirect('admin/testimonials'); }
            if ($id) { Database::update('testimonials', $data, $id); Audit::log('testimonial.update', 'testimonial', $id, 'Updated testimonial'); flash('success', 'Testimonial updated.'); }
            else { $id = Database::insert('testimonials', $data + ['created_at' => Database::now()]); Audit::log('testimonial.create', 'testimonial', $id, 'Added testimonial'); flash('success', 'Testimonial added.'); }
            redirect('admin/testimonials');
        }
        if ($action === 'delete' && $method === 'POST' && ctype_digit($segments[2] ?? '')) {
            verify_csrf();
            Database::query('DELETE FROM testimonials WHERE id=?', [(int)$segments[2]]);
            Audit::log('testimonial.delete', 'testimonial', (int)$segments[2], 'Deleted testimonial');
            flash('success', 'Testimonial deleted.');
            redirect('admin/testimonials');
        }
        abort_page(404, 'We couldn’t find that page.');
    }

    /* ---------------- Users ---------------- */
    private static function users(array $segments, string $method, array $user): void {
        Auth::require('users.manage');
        $action = $segments[1] ?? 'index';
        if ($action === 'index' && $method === 'GET') {
            self::layout('users/index', 'Staff & users', ['items' => Database::all('SELECT id,name,email,role,active,last_login,created_at FROM users ORDER BY active DESC, name')], $user, 'users');
            return;
        }
        if ($action === 'new' && $method === 'GET') { self::layout('users/form', 'Add staff member', ['edit' => null], $user, 'users'); return; }
        if ($action === 'create' && $method === 'POST') {
            verify_csrf();
            $data = self::userInput();
            $errors = self::userErrors($data);
            if (Database::one('SELECT id FROM users WHERE email=?', [$data['email']])) $errors[] = 'That email address is already registered.';
            if ($data['role'] === 'super_admin' && $user['role'] !== 'super_admin') $errors[] = 'Only a Super Administrator can grant that role.';
            if ($errors) { remember_old($_POST); foreach ($errors as $e) flash('error', $e); redirect('admin/users/new'); }
            $id = Database::insert('users', PasswordReset::guardFlag(['name' => $data['name'], 'email' => $data['email'], 'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT), 'role' => $data['role'], 'active' => 1, 'must_change_password' => 1, 'created_at' => Database::now()]));
            Audit::log('user.create', 'user', $id, "Created {$data['name']} ({$data['role']})");
            clear_old();
            flash('success', 'Staff member created. Share their sign-in details securely — they will be asked to choose their own password at first sign-in.');
            redirect('admin/users');
        }
        if (ctype_digit($action)) {
            $edit = Database::one('SELECT id,name,email,role,active FROM users WHERE id=?', [(int)$action]);
            if (!$edit) abort_page(404, 'That user could not be found.');
            $sub = $segments[2] ?? 'edit';
            if ($sub === 'edit' && $method === 'GET') { self::layout('users/form', 'Edit staff member', ['edit' => $edit], $user, 'users'); return; }
            if ($sub === 'edit' && $method === 'POST') {
                verify_csrf();
                $data = self::userInput(true);
                $errors = self::userErrors($data, true, (int)$edit['id']);
                if ($edit['id'] === $user['id'] && ($data['role'] !== $edit['role'] || isset($_POST['deactivate']))) $errors[] = 'You cannot change your own role or deactivate your own account.';
                // Guard the way back in. If every super administrator is
                // deactivated or demoted, nobody can reach settings or the
                // staff list again, and recovery needs direct database access.
                $losesSuperAdmin = $edit['role'] === 'super_admin' && (($data['role'] !== 'super_admin') || isset($_POST['deactivate']));
                if ($losesSuperAdmin && $edit['active']) {
                    $others = (int) Database::value(
                        "SELECT COUNT(*) FROM users WHERE role='super_admin' AND active=1 AND id<>?",
                        [(int)$edit['id']]
                    );
                    if ($others === 0) $errors[] = 'This is the last active super administrator. Give another account that role before removing it, or nobody will be able to manage the site.';
                }
                // Only a super administrator may hand out that role, so an
                // administrator cannot escalate their own access.
                if ($data['role'] === 'super_admin' && $user['role'] !== 'super_admin') {
                    $errors[] = 'Only a Super Administrator can grant that role.';
                }
                if ($errors) { remember_old($_POST); foreach ($errors as $e) flash('error', $e); redirect('admin/users/' . $edit['id'] . '/edit'); }
                $update = ['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role']];
                if ($data['password'] !== '') {
                    $update['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
                    $update['must_change_password'] = 1;
                    // Anyone still holding the old password is signed out.
                    $update['session_version'] = (int)Database::value('SELECT session_version FROM users WHERE id=?', [$edit['id']]) + 1;
                }
                if (isset($_POST['deactivate'])) { $update['active'] = 0; $update['session_version'] = (int)Database::value('SELECT session_version FROM users WHERE id=?', [$edit['id']]) + 1; }
                if (isset($_POST['reactivate'])) $update['active'] = 1;
                Database::update('users', PasswordReset::guardFlag($update), (int)$edit['id']);
                Audit::log('user.update', 'user', (int)$edit['id'], "Updated {$data['name']} ({$data['role']})");
                clear_old();
                flash('success', 'Staff member updated.');
                redirect('admin/users');
            }
            if ($sub === 'reset-password' && $method === 'POST') {
                verify_csrf();
                $temporary = PasswordReset::issueTemporary((int)$edit['id']);
                Audit::log('user.reset', 'user', (int)$edit['id'], 'Reset password (temporary issued)');
                flash('success', 'Temporary password: ' . $temporary . ' — share it securely; it will not be shown again. They must choose a new password when they sign in.');
                redirect('admin/users/' . $edit['id'] . '/edit');
            }
        }
        abort_page(404, 'We couldn’t find that user page.');
    }

    private static function userInput(bool $edit = false): array {
        $role = input('role');
        return ['name' => mb_substr(input('name'), 0, 120), 'email' => strtolower(mb_substr(input('email'), 0, 190)), 'role' => isset(Auth::PERMISSIONS[$role]) ? $role : 'content_editor', 'password' => (string)($_POST['password'] ?? ''), 'confirm' => (string)($_POST['password_confirm'] ?? '')];
    }

    private static function userErrors(array $data, bool $edit = false, ?int $id = null): array {
        $errors = [];
        if (mb_strlen($data['name']) < 2) $errors[] = 'Enter the staff member’s full name.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        $taken = Database::one('SELECT id FROM users WHERE email=?' . ($id ? ' AND id<>?' : ''), $id ? [$data['email'], $id] : [$data['email']]);
        if ($taken) $errors[] = 'That email address is already registered.';
        if (!$edit || $data['password'] !== '') {
            if (mb_strlen($data['password']) < 10) $errors[] = 'Passwords must be at least 10 characters.';
            if (!hash_equals($data['password'], $data['confirm'])) $errors[] = 'The two passwords do not match.';
        }
        return $errors;
    }

    /* ---------------- Settings ---------------- */
    private static function settings(string $method, array $user): void {
        Auth::require('settings.manage');
        $keys = ['brand_name', 'brand_tagline', 'tagline', 'phone', 'whatsapp', 'email', 'notify_email', 'address', 'office_hours', 'map_embed', 'facebook', 'instagram', 'twitter', 'linkedin', 'listings_per_page', 'google_verification', 'bing_verification'];
        if ($method === 'POST') {
            verify_csrf();
            $driver = Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME);
            $save = static function (string $key, string $value) use ($driver): void {
                if ($driver === 'mysql') Database::query('INSERT INTO settings (key_name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$key, $value]);
                else Database::query('INSERT INTO settings (key_name,value) VALUES (?,?) ON CONFLICT(key_name) DO UPDATE SET value=excluded.value', [$key, $value]);
            };
            foreach ($keys as $key) {
                $value = mb_substr((string)input($key), 0, $key === 'map_embed' ? 4000 : 500);
                if ($key === 'office_hours') {
                    // The contact page reads each opening time from a pipe-separated
                    // list, so store one entry per line as one pipe-separated entry.
                    $lines = preg_split('/\R+/u', $value) ?: [];
                    $lines = array_values(array_filter(array_map('trim', $lines), static fn($l) => $l !== ''));
                    $value = implode('|', $lines);
                }
                if ($key === 'listings_per_page') $value = (string)min(48, max(3, (int)$value ?: 9));
                if ($key === 'brand_name' && trim($value) === '') { flash('error', 'The website name cannot be empty.'); redirect('admin/settings'); }
                if (in_array($key, ['email', 'notify_email'], true) && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) { flash('error', 'Enter a valid email address.'); redirect('admin/settings'); }
                $save($key, $value);
            }
            // Legacy key kept in sync so older pages and templates stay consistent.
            $save('site_name', mb_substr((string)input('brand_name'), 0, 500));

            // Brand logo upload / removal.
            if (isset($_FILES['brand_logo']) && is_array($_FILES['brand_logo'])) {
                [$logoPath, $logoError] = Uploads::storeLogo($_FILES['brand_logo']);
                if ($logoError !== null) { flash('error', $logoError); redirect('admin/settings'); }
                if ($logoPath !== null) {
                    Uploads::deleteLogo();
                    $save('brand_logo', $logoPath);
                    Audit::log('settings.logo', 'settings', null, 'Updated the brand logo');
                }
            }
            if (input('remove_logo') === '1') {
                Uploads::deleteLogo();
                $save('brand_logo', '');
                Audit::log('settings.logo', 'settings', null, 'Removed the brand logo');
            }
            Audit::log('settings.update', 'settings', null, 'Updated website settings');
            flash('success', 'Settings saved. Changes appear on the website immediately.');
            redirect('admin/settings');
        }
        $settings = array_column(Database::all('SELECT key_name, value FROM settings'), 'value', 'key_name');
        self::layout('settings', 'Website settings', ['settings' => $settings], $user, 'settings');
    }

    /* ---------------- Audit, roadmap, account ---------------- */
    private static function audit(array $user): void {
        Auth::require('audit.view');
        $page = max(1, (int)query_value('page', '1'));
        $perPage = 30;
        $total = (int)Database::value('SELECT COUNT(*) FROM audit_logs');
        $items = Database::all('SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        self::layout('audit', 'Activity log', ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage))], $user, 'audit');
    }

    private static function roadmap(array $user): void {
        self::layout('roadmap', 'Future agent portal', [], $user, 'roadmap');
    }

    private static function account(string $method, array $user): void {
        if ($method === 'POST') {
            verify_csrf();
            $name = mb_substr(input('name'), 0, 120);
            if (mb_strlen($name) < 2) { flash('error', 'Enter your full name.'); redirect('admin/account'); }
            $update = ['name' => $name];
            if ((string)($_POST['password'] ?? '') !== '') {
                $full = Database::one('SELECT password_hash, session_version FROM users WHERE id=?', [$user['id']]);
                if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$full['password_hash'])) { flash('error', 'Your current password is incorrect.'); redirect('admin/account'); }
                $problem = PasswordReset::validate((string)$_POST['password'], (string)($_POST['password_confirm'] ?? ''));
                if ($problem !== null) { flash('error', $problem); redirect('admin/account'); }
                $update['password_hash'] = password_hash((string)$_POST['password'], PASSWORD_DEFAULT);
                $update['must_change_password'] = 0;
                $update['session_version'] = (int)$full['session_version'] + 1;
                $_SESSION['session_version'] = (int)$full['session_version'] + 1;
            }
            Database::update('users', PasswordReset::guardFlag($update), (int)$user['id']);
            Audit::log('user.account', 'user', (int)$user['id'], 'Updated own profile');
            flash('success', 'Your profile was updated.');
            redirect('admin/account');
        }
        self::layout('account', 'My account', ['account' => Database::one('SELECT id,name,email,role,last_login FROM users WHERE id=?', [$user['id']])], $user, 'account');
    }
}
