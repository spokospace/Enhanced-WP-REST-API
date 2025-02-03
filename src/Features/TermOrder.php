<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\ErrorLogger;

class TermOrder
{
    private const TAXONOMIES = ['category', 'post_tag'];

    public function __construct(
        private ErrorLogger $logger
    ) {}

    public function register(): void
    {
        foreach (self::TAXONOMIES as $taxonomy) {
            add_filter("rest_{$taxonomy}_collection_params", [$this, 'addOrderParam']);
            add_filter("rest_prepare_{$taxonomy}", [$this, 'modifyTermLink'], 10, 3);
            register_rest_field($taxonomy, 'term_order', [
                'get_callback' => [$this, 'getTermOrder'],
                'schema' => [
                    'type' => 'integer',
                    'default' => 0,
                    'orderby' => true
                ],
            ]);
        }

        add_filter('terms_clauses', [$this, 'modifyTermsClauses'], 10, 3);
        add_filter('get_terms_args', [$this, 'setDefaultTermOrder'], 10, 2);
    }

    public function addOrderParam(array $params): array
    {
        $params['orderby']['enum'][] = 'term_order';
        return $params;
    }

    public function modifyTermsClauses(array $clauses, array $taxonomies, array $args): array
    {
        if (isset($args['orderby']) && $args['orderby'] === 'term_order') {
            $clauses['orderby'] = 'ORDER BY t.term_order, t.term_id';
        }
        return $clauses;
    }

    public function setDefaultTermOrder(array $args, array $taxonomies): array
    {
        if (empty($args['orderby']) && array_intersect(self::TAXONOMIES, (array)$taxonomies)) {
            $args['orderby'] = 'term_order';
            $args['order'] = 'ASC';
        }
        return $args;
    }

    public function getTermOrder(array $term): int
    {
        try {
            global $wpdb;
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT term_order FROM {$wpdb->terms} WHERE term_id = %d",
                $term['id']
            ));
        } catch (\Exception $e) {
            $this->logger->logError('Error getting term order', ['term_id' => $term['id'], 'error' => $e->getMessage()]);
            return 0;
        }
    }

    public function modifyTermLink(\WP_REST_Response $response, \WP_Term $term): \WP_REST_Response
    {
        try {
            $response->data['link'] = wp_make_link_relative(get_term_link($term));
            return $response;
        } catch (\Exception $e) {
            $this->logger->logError('Error modifying term link', ['term_id' => $term->term_id, 'error' => $e->getMessage()]);
            return $response;
        }
    }
}