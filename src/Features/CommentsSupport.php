<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\ErrorLogger;

class CommentsSupport
{
    public function __construct(
        private ErrorLogger $logger
    ) {}

    /**
     * Register REST API fields and filters (called from rest_api_init)
     */
    public function registerRestFields(): void
    {
        // Check if anonymous comments feature is enabled
        if (get_option('spoko_rest_anonymous_comments_enabled', '0') === '1') {
            add_filter('rest_allow_anonymous_comments', [$this, 'allowAnonymousComments'], 10, 2);
        }

        // Check if comment notifications feature is enabled
        if (get_option('spoko_rest_comment_notifications_enabled', '0') === '1') {
            add_action('wp_insert_comment', [$this, 'sendCommentNotification'], 10, 2);
        }
    }

    public function allowAnonymousComments(bool $allow_anonymous, \WP_REST_Request $request): bool
    {
        return true;
    }

    public function sendCommentNotification(int $comment_id, \WP_Comment $comment): void
    {
        try {
            // For approved comments: notify post author
            if ($comment->comment_approved === '1') {
                if (function_exists('wp_new_comment_notify_postauthor')) {
                    wp_new_comment_notify_postauthor($comment_id);
                }
            } else {
                // For pending/unapproved comments: notify moderators
                if (function_exists('wp_new_comment_notify_moderator')) {
                    wp_new_comment_notify_moderator($comment_id);
                }
            }
        } catch (\Exception $e) {
            $this->logger->logError('Error sending comment notification', [
                'comment_id' => $comment_id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
