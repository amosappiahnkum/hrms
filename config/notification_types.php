<?php

/**
 * Notification type registry.
 *
 * Each entry defines a category group and its notification types.
 * The keys (e.g. 'leave_status_updated') are the canonical type identifiers
 * stored in user_notification_preferences.notification_type.
 *
 * To register a new notification type:
 *   1. Add it to the appropriate group (or create a new group).
 *   2. Use HasNotificationPreferences::via($notifiable, 'your_type_key') in your Notification class.
 *   3. That's it — the settings page will automatically pick it up.
 */
return [
    'leave' => [
        'label' => 'Leave Management',
        'description' => 'Notifications related to leave requests and status changes.',
        'icon' => 'calendar',
        'types' => [
            'leave_request_submitted' => [
                'label' => 'Leave Request Submitted',
                'description' => 'When an employee in your team submits a leave request that requires your approval.',
                'default_email' => true,
                'default_in_app' => true,
            ],
            'leave_status_updated' => [
                'label' => 'Leave Request Status Update',
                'description' => 'When your leave request is approved or rejected by HOD or HR.',
                'default_email' => true,
                'default_in_app' => true,
            ],
            'leave_hr_action_required' => [
                'label' => 'Leave Pending HR Action',
                'description' => 'When a leave request is approved by HOD and awaiting your HR decision.',
                'default_email' => true,
                'default_in_app' => true,
            ],
        ],
    ],

    'information_updates' => [
        'label' => 'Information Updates',
        'description' => 'Notifications about employee profile information change requests.',
        'icon' => 'user',
        'types' => [
            'info_update_submitted' => [
                'label' => 'Information Update Request',
                'description' => 'When an employee submits a profile information update for review.',
                'default_email' => true,
                'default_in_app' => true,
            ],
            'info_update_decided' => [
                'label' => 'Information Update Decision',
                'description' => 'When your profile information update request is approved or rejected.',
                'default_email' => true,
                'default_in_app' => true,
            ],
        ],
    ],

    // Add future groups here — e.g.:
    // 'certifications' => [
    //     'label' => 'Certifications',
    //     'description' => 'Reminders about expiring certifications.',
    //     'icon' => 'trophy',
    //     'types' => [
    //         'certification_expiry_reminder' => [
    //             'label' => 'Expiring Certification Reminder',
    //             'description' => 'When one of your certifications is about to expire.',
    //             'default_email' => true,
    //             'default_in_app' => true,
    //         ],
    //     ],
    // ],
];
