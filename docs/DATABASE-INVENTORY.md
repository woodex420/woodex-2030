# WOODEX — Database Inventory

Source: `supabase/migrations/*.sql` + `supabase/tables/*.sql` in `woodex420/woodex@woodex-admin`.

**45 tables defined.** 53 `ENABLE ROW LEVEL SECURITY` statements, 106 `CREATE POLICY` statements.

> ⚠️ Derived from migration files, not from the live database (see MASTER-PLAN §3 — the Supabase API is unreachable from this environment). Row counts and live drift are unknown.


## Identity & access

| Table | Cols | Primary key | References |
|---|---:|---|---|
| `profiles` | 9 | id | auth |
| `admin_users` | 8 | id | — |
| `user_permissions` | 8 | id | — |
| `user_activity_log` | 8 | id | — |
| `user_presence` | 6 | id | — |

## Catalog & inventory

| Table | Cols | Primary key | References |
|---|---:|---|---|
| `products` | 15 | id | — |
| `product_variants` | 11 | id | — |
| `categories` | 10 | id | — |
| `inventory` | 11 | id | products |
| `stock_movements` | 10 | id | products, profiles |
| `media_assets` | 11 | id | — |
| `pricing_rules` | 14 | id | — |

## Customers & CRM

| Table | Cols | Primary key | References |
|---|---:|---|---|
| `customers` | 17 | id | — |
| `customer_addresses` | 15 | id | auth |
| `customer_interactions` | 7 | id | — |
| `customer_journey_events` | 10 | id | customers |
| `b2b_companies` | 16 | id | — |
| `b2b_users` | 12 | id | — |

## Sales: quotations & orders

| Table | Cols | Primary key | References |
|---|---:|---|---|
| `quotations` | 15 | id | profiles |
| `quotation_items` | 17 | id | — |
| `quotation_activities` | 9 | id | — |
| `quotation_templates` | 12 | id | — |
| `orders` | 10 | id | auth, profiles |
| `order_items` | 10 | id | orders, products |
| `order_status_history` | 8 | id | orders, profiles |
| `cart_items` | 7 | id | auth, products |

## Fulfilment

| Table | Cols | Primary key | References |
|---|---:|---|---|
| `deliveries` | 20 | id | delivery_zones, orders |
| `delivery_zones` | 8 | id | — |
| `deliverables` | 16 | id | orders |
| `returns` | 17 | id | orders, products, profiles |

## WhatsApp & comms

| Table | Cols | Primary key | References |
|---|---:|---|---|
| `whatsapp_conversations` | 12 | id | admin_users, customers |
| `whatsapp_messages` | 13 | id | — |
| `whatsapp_templates` | 9 | id | — |
| `whatsapp_campaigns` | 15 | id | admin_users, whatsapp_templates |
| `whatsapp_automation_rules` | 8 | id | — |
| `whatsapp_analytics` | 11 | id | — |
| `whatsapp_appointments` | 14 | id | customers |

## Showroom & experience

| Table | Cols | Primary key | References |
|---|---:|---|---|
| `virtual_rooms` | 12 | id | — |
| `collaboration_sessions` | 7 | id | — |
| `room_packages` | 16 | id | — |

## Content & marketing

| Table | Cols | Primary key | References |
|---|---:|---|---|
| `blog_posts` | 13 | id | — |
| `services` | 9 | id | — |
| `testimonials` | 10 | id | — |
| `faqs` | 8 | id | — |

## Analytics

| Table | Cols | Primary key | References |
|---|---:|---|---|
| `analytics_daily` | 11 | id | — |

## Column detail

- **`profiles`** — `id`, `full_name`, `role`, `email`, `phone`, `department`, `avatar_url`, `created_at`, `updated_at`
- **`admin_users`** — `id`, `email`, `full_name`, `role`, `avatar_url`, `active`, `created_at`, `last_login`
- **`user_permissions`** — `id`, `user_id`, `module`, `can_view`, `can_create`, `can_edit`, `can_delete`, `created_at`
- **`user_activity_log`** — `id`, `user_id`, `action`, `resource_type`, `resource_id`, `metadata`, `ip_address`, `created_at`
- **`user_presence`** — `id`, `user_id`, `session_id`, `cursor_position`, `last_activity`, `is_active`
- **`products`** — `id`, `name`, `slug`, `sku`, `description`, `category_id`, `price`, `cost_price`, `images`, `is_active`, `is_featured`, `is_customizable`, `stock_status`, `created_at`, `updated_at`
- **`product_variants`** — `id`, `product_id`, `variant_name`, `sku`, `material`, `finish`, `color`, `price_adjustment`, `images`, `is_active`, `created_at`
- **`categories`** — `id`, `name`, `slug`, `description`, `parent_id`, `image_url`, `sort_order`, `is_active`, `created_at`, `updated_at`
- **`inventory`** — `id`, `product_id`, `variant_id`, `location`, `stock_quantity`, `reserved_quantity`, `low_stock_threshold`, `reorder_point`, `reorder_quantity`, `last_restocked`, `updated_at`
- **`stock_movements`** — `id`, `product_id`, `variant_id`, `movement_type`, `quantity`, `reference_type`, `reference_id`, `reason`, `performed_by`, `created_at`
- **`media_assets`** — `id`, `name`, `file_type`, `file_size`, `mime_type`, `storage_path`, `public_url`, `uploaded_by`, `tags`, `metadata`, `created_at`
- **`pricing_rules`** — `id`, `rule_name`, `rule_type`, `min_quantity`, `max_quantity`, `discount_percentage`, `customer_tier`, `product_category`, `is_active`, `priority`, `start_date`, `end_date`, `created_at`, `updated_at`
- **`customers`** — `id`, `customer_type`, `full_name`, `company_name`, `email`, `phone`, `whatsapp_number`, `address`, `tax_id`, `lead_source`, `lead_score`, `status`, `assigned_to`, `notes`, `tags`, `created_at`, `updated_at`
- **`customer_addresses`** — `id`, `user_id`, `type`, `title`, `full_name`, `phone`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `country`, `is_default`, `created_at`, `updated_at`
- **`customer_interactions`** — `id`, `customer_id`, `interaction_type`, `subject`, `content`, `created_by`, `created_at`
- **`customer_journey_events`** — `id`, `customer_id`, `session_id`, `event_type`, `event_data`, `page_url`, `referrer_url`, `user_agent`, `ip_address`, `timestamp`
- **`b2b_companies`** — `id`, `company_name`, `company_type`, `registration_number`, `tax_id`, `billing_address`, `shipping_address`, `credit_limit`, `payment_terms`, `discount_tier`, `contact_person`, `contact_email`, `contact_phone`, `is_active`, `created_at`, `updated_at`
- **`b2b_users`** — `id`, `user_id`, `company_id`, `role`, `department`, `approval_limit`, `can_approve_orders`, `can_place_orders`, `can_view_pricing`, `is_active`, `created_at`, `updated_at`
- **`quotations`** — `id`, `quote_number`, `customer_id`, `status`, `subtotal`, `tax_amount`, `discount_amount`, `shipping_cost`, `total_amount`, `currency`, `valid_until`, `notes`, `created_by`, `created_at`, `updated_at`
- **`quotation_items`** — `id`, `quotation_id`, `product_id`, `product_name`, `product_sku`, `product_description`, `quantity`, `unit_price`, `subtotal`, `discount_percentage`, `discount_amount`, `total_price`, `customization_options`, `specifications`, `image_url`, `created_at`, `updated_at`
- **`quotation_activities`** — `id`, `quotation_id`, `activity_type`, `description`, `metadata`, `user_id`, `user_name`, `ip_address`, `created_at`
- **`quotation_templates`** — `id`, `name`, `description`, `template_type`, `items`, `total_amount`, `discount_percentage`, `is_active`, `usage_count`, `created_by`, `created_at`, `updated_at`
- **`orders`** — `id`, `order_number`, `customer_id`, `quotation_id`, `status`, `payment_status`, `subtotal`, `total`, `created_at`, `updated_at`
- **`order_items`** — `id`, `order_id`, `product_id`, `product_name`, `product_sku`, `product_image`, `quantity`, `unit_price`, `total_price`, `created_at`
- **`order_status_history`** — `id`, `order_id`, `old_status`, `new_status`, `changed_by`, `change_reason`, `notes`, `created_at`
- **`cart_items`** — `id`, `session_id`, `user_id`, `product_id`, `quantity`, `created_at`, `updated_at`
- **`deliveries`** — `id`, `order_id`, `delivery_type`, `delivery_zone_id`, `tracking_number`, `courier_name`, `delivery_cost`, `status`, `scheduled_date`, `scheduled_time_slot`, `pickup_date`, `delivered_date`, `delivery_address`, `recipient_name`, `recipient_phone`, `delivery_instructions`, `proof_of_delivery_url`, `delivery_notes`, `created_at`, `updated_at`
- **`delivery_zones`** — `id`, `zone_name`, `postal_codes`, `cities`, `delivery_types`, `is_active`, `created_at`, `updated_at`
- **`deliverables`** — `id`, `order_id`, `tracking_number`, `courier_name`, `delivery_type`, `status`, `scheduled_date`, `delivered_date`, `delivery_cost`, `delivery_address`, `recipient_name`, `recipient_phone`, `notes`, `proof_of_delivery`, `created_at`, `updated_at`
- **`returns`** — `id`, `order_id`, `return_number`, `reason`, `reason_category`, `return_type`, `status`, `items_to_return`, `refund_amount`, `customer_notes`, `admin_notes`, `images`, `approved_by`, `approved_at`, `completed_at`, `created_at`, `updated_at`
- **`whatsapp_conversations`** — `id`, `customer_id`, `whatsapp_number`, `status`, `last_message_at`, `total_messages`, `lead_score`, `customer_stage`, `assigned_to`, `metadata`, `created_at`, `updated_at`
- **`whatsapp_messages`** — `id`, `message_id`, `customer_id`, `phone_number`, `direction`, `message_type`, `content`, `media_url`, `status`, `response_to`, `automation_triggered`, `created_at`, `updated_at`
- **`whatsapp_templates`** — `id`, `name`, `category`, `content`, `variables`, `is_active`, `usage_count`, `created_at`, `updated_at`
- **`whatsapp_campaigns`** — `id`, `name`, `description`, `target_segment`, `message_template_id`, `message_content`, `scheduled_at`, `status`, `sent_count`, `delivered_count`, `read_count`, `response_count`, `created_by`, `created_at`, `updated_at`
- **`whatsapp_automation_rules`** — `id`, `name`, `trigger_type`, `trigger_value`, `action_type`, `action_config`, `is_active`, `created_at`
- **`whatsapp_analytics`** — `id`, `metric_date`, `total_messages_sent`, `total_messages_received`, `total_conversations`, `active_conversations`, `response_rate`, `avg_response_time_minutes`, `conversion_count`, `revenue_attributed`, `created_at`
- **`whatsapp_appointments`** — `id`, `customer_id`, `appointment_type`, `scheduled_at`, `duration_minutes`, `location`, `virtual_meeting_link`, `status`, `notes`, `reminder_sent_at`, `confirmed_at`, `cancelled_at`, `created_at`, `updated_at`
- **`virtual_rooms`** — `id`, `user_id`, `room_name`, `room_type`, `dimensions`, `configuration_data`, `products_placed`, `thumbnail_url`, `is_public`, `is_saved`, `created_at`, `updated_at`
- **`collaboration_sessions`** — `id`, `session_name`, `resource_type`, `resource_id`, `active_users`, `created_at`, `expires_at`
- **`room_packages`** — `id`, `name`, `slug`, `package_type`, `description`, `base_price`, `discount_percentage`, `featured_image`, `gallery_images`, `included_products`, `customization_options`, `is_active`, `is_featured`, `display_order`, `created_at`, `updated_at`
- **`blog_posts`** — `id`, `title`, `content`, `excerpt`, `featured_image`, `author`, `published`, `slug`, `meta_title`, `meta_description`, `created_at`, `updated_at`, `published_at`
- **`services`** — `id`, `title`, `description`, `features`, `icon`, `order_index`, `active`, `created_at`, `updated_at`
- **`testimonials`** — `id`, `customer_name`, `company`, `review`, `rating`, `photo_url`, `approved`, `active`, `created_at`, `updated_at`
- **`faqs`** — `id`, `question`, `answer`, `category`, `order_index`, `active`, `created_at`, `updated_at`
- **`analytics_daily`** — `id`, `date`, `messages_sent`, `messages_received`, `leads_generated`, `quotations_created`, `quotations_accepted`, `orders_created`, `revenue`, `active_users`, `created_at`
