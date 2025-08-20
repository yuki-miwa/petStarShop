# OpenAPI Gap Notes - petStarShop EC Site

This document outlines the differences between the current OpenAPI specification and the implemented database schema/business logic. These gaps should be addressed in future PRs to keep the API documentation in sync with the actual implementation.

## 1. Missing Endpoints

### 1.1 Reorder Functionality
**Status**: Not implemented in OpenAPI
**Priority**: High
**Description**: Allow users to reorder previous orders by creating a new design based on the original.

```yaml
# To be added to openapi-ec.yml
/orders/{id}/reorder:
  post:
    summary: Create a reorder based on an existing order
    parameters:
      - name: id
        in: path
        required: true
        schema:
          type: string
          format: uuid
    responses:
      200:
        description: New design created for reorder
        content:
          application/json:
            schema:
              type: object
              properties:
                design_id:
                  type: string
                  format: uuid
                message:
                  type: string
                  example: "Design created for reorder. You can now edit and place a new order."
      404:
        description: Order not found
      400:
        description: Template no longer available or order cannot be reordered
```

### 1.2 CSV Export Jobs
**Status**: Not implemented in OpenAPI
**Priority**: Medium
**Description**: Admin endpoints for creating and managing CSV export jobs.

```yaml
# To be added to openapi-ec.yml
/admin/exports:
  post:
    summary: Create a new CSV export job
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            properties:
              type:
                type: string
                enum: [orders_csv, users_csv, designs_csv]
              parameters:
                type: object
                properties:
                  date_from:
                    type: string
                    format: date
                  date_to:
                    type: string
                    format: date
                  status_filter:
                    type: array
                    items:
                      type: string
    responses:
      201:
        description: Export job created
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/ExportJob'

  get:
    summary: List export jobs
    parameters:
      - name: status
        in: query
        schema:
          type: string
          enum: [queued, processing, completed, failed]
    responses:
      200:
        description: List of export jobs
        content:
          application/json:
            schema:
              type: object
              properties:
                data:
                  type: array
                  items:
                    $ref: '#/components/schemas/ExportJob'

/admin/exports/{id}:
  get:
    summary: Get export job details
    parameters:
      - name: id
        in: path
        required: true
        schema:
          type: string
          format: uuid
    responses:
      200:
        description: Export job details
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/ExportJob'

/admin/exports/{id}/download:
  get:
    summary: Download export file
    parameters:
      - name: id
        in: path
        required: true
        schema:
          type: string
          format: uuid
    responses:
      200:
        description: File download
        content:
          text/csv:
            schema:
              type: string
              format: binary
      404:
        description: File not found or not ready
```

## 2. Schema Updates Required

### 2.1 User Model Extensions
**Current Issues**: Missing fields that are now in the database schema.

```yaml
# Updates needed in components/schemas/User
User:
  type: object
  properties:
    id:
      type: string
      format: uuid
    email:
      type: string
      format: email
    name:
      type: string
    phone:  # NEW FIELD
      type: string
      nullable: true
      pattern: '^[0-9\-\+\(\)\s]+$'
    status:  # NEW FIELD
      type: string
      enum: [active, inactive, suspended]
      default: active
    email_verified_at:
      type: string
      format: date-time
      nullable: true
    last_login_at:  # NEW FIELD
      type: string
      format: date-time
      nullable: true
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
```

### 2.2 Design Model Extensions
**Current Issues**: Missing new fields for versioning, CRC validation, and reorder tracking.

```yaml
# Updates needed in components/schemas/Design
Design:
  type: object
  properties:
    id:
      type: string
      format: uuid
    user_id:
      type: string
      format: uuid
    template_id:
      type: string
      format: uuid
    original_design_id:  # NEW FIELD
      type: string
      format: uuid
      nullable: true
      description: "Reference to original design for reorders"
    name:
      type: string
    version:  # NEW FIELD
      type: integer
      default: 1
      description: "Design version number"
    params:
      type: object
      description: "Design parameters as JSON"
    params_crc32:  # NEW FIELD
      type: integer
      nullable: true
      description: "CRC32 hash of params for change detection"
    safe_area_warning:  # NEW FIELD
      type: boolean
      default: false
      description: "Whether design has elements outside safe area"
    preview_image_url:
      type: string
      format: uri
      nullable: true
    status:
      type: string
      enum: [draft, queued, rendering, ready, failed]  # UPDATED ENUM
      default: draft
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
```

### 2.3 Order Model Extensions
**Current Issues**: Missing detailed pricing breakdown, shipping address normalization, and payment tracking.

```yaml
# Updates needed in components/schemas/Order
Order:
  type: object
  properties:
    id:
      type: string
      format: uuid
    user_id:
      type: string
      format: uuid
    design_id:
      type: string
      format: uuid
    order_number:
      type: string
    quantity:
      type: integer
      minimum: 1
    
    # DETAILED PRICING BREAKDOWN (NEW FIELDS)
    base_unit_price:
      type: integer
      description: "Base price per unit in yen"
    subtotal:
      type: integer
      description: "base_unit_price * quantity"
    discount_total:
      type: integer
      default: 0
      description: "Total discount amount in yen"
    subtotal_after_discount:
      type: integer
      description: "subtotal - discount_total"
    shipping_fee:
      type: integer
      default: 0
      description: "Shipping fee in yen (0 if >= 8000 yen after discount)"
    amount:
      type: integer
      description: "Final amount: subtotal_after_discount + shipping_fee"
    amount_breakdown:  # NEW FIELD
      type: object
      description: "Detailed breakdown of amount calculation"
    
    # ENHANCED SHIPPING ADDRESS (NEW FIELDS)
    shipping_name:
      type: string
    shipping_postal_code:
      type: string
      pattern: '^\d{3}-?\d{4}$'
    shipping_pref_code:  # NEW FIELD
      type: string
      description: "Prefecture code (01-47)"
    shipping_pref_name:
      type: string
    shipping_pref_kana:  # NEW FIELD
      type: string
      nullable: true
      description: "Prefecture name in katakana"
    shipping_city:
      type: string
    shipping_city_kana:  # NEW FIELD
      type: string
      nullable: true
      description: "City name in katakana"
    shipping_address_line1:
      type: string
    shipping_address_line2:
      type: string
      nullable: true
    shipping_phone:
      type: string
      nullable: true
    shipping:  # NEW FIELD
      type: object
      nullable: true
      description: "Additional shipping metadata"
    
    # ENHANCED STATUS TRACKING
    status:
      type: string
      enum: [pending, confirmed, processing, shipped, delivered, cancelled, refunded]  # UPDATED ENUM
    payment_status:  # NEW FIELD
      type: string
      enum: [pending, completed, failed, refunded, partially_refunded]
    payment_method:  # NEW FIELD
      type: string
      nullable: true
    stripe_payment_intent_id:  # NEW FIELD
      type: string
      nullable: true
    
    # ENHANCED TIMESTAMPS
    confirmed_at:  # NEW FIELD
      type: string
      format: date-time
      nullable: true
    shipped_at:  # NEW FIELD
      type: string
      format: date-time
      nullable: true
    delivered_at:  # NEW FIELD
      type: string
      format: date-time
      nullable: true
    cancelled_at:  # NEW FIELD
      type: string
      format: date-time
      nullable: true
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
```

### 2.4 Template Model Extensions
**Current Issues**: Missing sorting and status management fields.

```yaml
# Updates needed in components/schemas/Template
Template:
  type: object
  properties:
    id:
      type: string
      format: uuid
    name:
      type: string
    description:
      type: string
      nullable: true
    category:
      type: string
    base_unit_price:
      type: integer
      description: "Base price in yen"
    safe_area:
      type: object
      description: "Safe area coordinates and dimensions"
    preview_image_url:
      type: string
      format: uri
      nullable: true
    template_file_url:
      type: string
      format: uri
      nullable: true
    status:  # NEW FIELD
      type: string
      enum: [active, inactive, archived]
      default: active
    sort_order:  # NEW FIELD
      type: integer
      default: 0
      description: "Display order for template listing"
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
```

## 3. New Schema Definitions Required

### 3.1 AdminUser Schema
**Status**: Not defined in OpenAPI
**Priority**: Medium

```yaml
AdminUser:
  type: object
  properties:
    id:
      type: string
      format: uuid
    email:
      type: string
      format: email
    name:
      type: string
    role:
      type: string
      enum: [super_admin, admin, editor, viewer]
    is_active:
      type: boolean
    email_verified_at:
      type: string
      format: date-time
      nullable: true
    last_login_at:
      type: string
      format: date-time
      nullable: true
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
```

### 3.2 RenderJob Schema
**Status**: Not defined in OpenAPI
**Priority**: Low (internal use)

```yaml
RenderJob:
  type: object
  properties:
    id:
      type: string
      format: uuid
    design_id:
      type: string
      format: uuid
    idempotency_key:
      type: string
    status:
      type: string
      enum: [queued, processing, completed, failed, cancelled]
    attempt:
      type: integer
    failure_reason:
      type: string
      nullable: true
    output_image_url:
      type: string
      format: uri
      nullable: true
    started_at:
      type: string
      format: date-time
      nullable: true
    completed_at:
      type: string
      format: date-time
      nullable: true
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
```

### 3.3 ExportJob Schema
**Status**: Not defined in OpenAPI
**Priority**: Medium

```yaml
ExportJob:
  type: object
  properties:
    id:
      type: string
      format: uuid
    admin_user_id:
      type: string
      format: uuid
    type:
      type: string
      enum: [orders_csv, users_csv, designs_csv]
    status:
      type: string
      enum: [queued, processing, completed, failed]
    parameters:
      type: object
    file_url:
      type: string
      format: uri
      nullable: true
    error_message:
      type: string
      nullable: true
    progress_percentage:
      type: integer
      minimum: 0
      maximum: 100
    started_at:
      type: string
      format: date-time
      nullable: true
    completed_at:
      type: string
      format: date-time
      nullable: true
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
```

## 4. Webhook Endpoints

### 4.1 Stripe Webhook
**Status**: Not documented in OpenAPI
**Priority**: High

```yaml
/webhook/stripe:
  post:
    summary: Stripe webhook endpoint
    description: Handles Stripe events for payment processing
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            properties:
              id:
                type: string
              type:
                type: string
              data:
                type: object
    responses:
      200:
        description: Webhook processed successfully
      400:
        description: Invalid signature or malformed request
```

## 5. Error Response Enhancements

### 5.1 Business Logic Errors
**Current Issues**: Need specific error codes for business logic violations.

```yaml
# Additional error responses needed
components:
  schemas:
    BusinessLogicError:
      type: object
      properties:
        error:
          type: string
          enum:
            - TEMPLATE_INACTIVE
            - DESIGN_NOT_READY
            - INSUFFICIENT_STOCK
            - INVALID_DISCOUNT_CODE
            - PAYMENT_PROCESSING_ERROR
            - RENDER_FAILED
            - SAFE_AREA_VIOLATION
        message:
          type: string
        details:
          type: object
```

## 6. Implementation Priorities

### High Priority (Next PR)
1. Reorder endpoint (`/orders/{id}/reorder`)
2. Updated Order and Design schemas with new fields
3. Enhanced error responses for business logic

### Medium Priority
1. CSV Export job endpoints
2. AdminUser schema and endpoints
3. Template status management endpoints

### Low Priority
1. RenderJob monitoring endpoints (admin only)
2. Rate limiting documentation
3. WebSocket event documentation

## 7. Validation Rules Updates

The OpenAPI should include the business logic validation rules that are enforced at the database level:

- Postal code format: `^\d{3}-?\d{4}$`
- Free shipping rule: `subtotal_after_discount >= 8000 → shipping_fee = 0`
- Amount calculation: `amount = subtotal_after_discount + shipping_fee`
- Subtotal calculation: `subtotal = base_unit_price * quantity`
- All monetary amounts must be non-negative integers (yen)

## 8. Next Steps

1. Create issues for each high-priority gap
2. Update openapi-ec.yml file with new schemas and endpoints
3. Implement missing endpoints in Laravel controllers
4. Add validation middleware for business rules
5. Update API documentation generation process
6. Add integration tests for new endpoints