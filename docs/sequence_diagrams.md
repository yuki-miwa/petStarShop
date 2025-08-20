# Sequence Diagrams - petStarShop EC Site

This document contains the main sequence diagrams for the petStarShop EC site core flows.

## 1. Checkout Flow

```mermaid
sequenceDiagram
    participant U as User
    participant F as Frontend
    participant B as Backend API
    participant DB as Database
    participant S as Stripe
    participant R as Render Service

    U->>F: Select design and proceed to checkout
    F->>B: GET /designs/{id}
    B->>DB: Fetch design details
    DB-->>B: Design data
    B-->>F: Design with pricing info
    
    F->>B: POST /orders (create order)
    B->>DB: Calculate pricing (base_unit_price, shipping)
    note over B,DB: Apply coupon logic<br/>Free shipping if subtotal >= 8000 yen
    B->>DB: Create order record (status: pending)
    DB-->>B: Order created
    B-->>F: Order details with amount

    F->>B: POST /stripe/checkout-session
    B->>S: Create Stripe Checkout Session
    S-->>B: Session URL
    B-->>F: Checkout session URL

    U->>F: Redirect to Stripe Checkout
    F->>S: User completes payment
    S-->>F: Payment success/failure

    alt Payment Success
        S->>B: Webhook: payment_intent.succeeded
        B->>DB: Update order (payment_status: paid, status: confirmed)
        B->>R: Queue final render job
        R-->>B: Render job queued
        B->>DB: Create render_job record
        B-->>S: Webhook processed (200 OK)
    else Payment Failed
        S->>B: Webhook: payment_intent.payment_failed
        B->>DB: Update order (payment_status: failed, status: cancelled)
        B-->>S: Webhook processed (200 OK)
    end

    F->>B: GET /orders/{id}/status
    B->>DB: Fetch order status
    DB-->>B: Order status
    B-->>F: Order status update
    F-->>U: Show order confirmation/failure
```

## 2. Final Render Process

```mermaid
sequenceDiagram
    participant O as Order System
    participant R as Render Worker
    participant Q as Queue System
    participant DB as Database
    participant S as Storage (S3)
    participant N as Notification

    O->>Q: Queue render job (design_id, idempotency_key)
    Q->>R: Process render job
    
    R->>DB: Update render_job (status: processing, started_at)
    R->>DB: Fetch design params and template data
    DB-->>R: Design and template data
    
    R->>R: Generate high-resolution image
    note over R: Apply user customization params<br/>Validate safe area constraints
    
    alt Render Success
        R->>S: Upload final image
        S-->>R: Image URL
        R->>DB: Update render_job (status: completed, result_image_url)
        R->>DB: Update design (final_image_url, status: ready)
        R->>DB: Update order processing status
        R->>N: Send completion notification
    else Render Failure
        R->>DB: Update render_job (status: failed, failure_reason)
        R->>DB: Update design (status: failed)
        note over R,DB: Increment attempt counter<br/>Retry if attempt < max_retries
        alt Retry Available
            R->>Q: Requeue job with incremented attempt
        else Max Retries Exceeded
            R->>N: Send failure notification
            R->>DB: Mark order as processing_failed
        end
    end
```

## 3. Preview Generation (Sync/Async)

### 3.1 Synchronous Preview (for immediate feedback)

```mermaid
sequenceDiagram
    participant U as User
    participant F as Frontend
    participant B as Backend API
    participant R as Render Service
    participant C as Cache (Redis)

    U->>F: Modify design parameters
    F->>B: POST /designs/{id}/preview (with params)
    
    B->>B: Calculate params_crc32
    B->>C: Check cache (design_id + params_crc32)
    
    alt Cache Hit
        C-->>B: Cached preview URL
        B-->>F: Preview URL (cached)
    else Cache Miss
        B->>R: Generate low-res preview (sync)
        note over R: Quick render for preview<br/>Lower resolution, faster processing
        R-->>B: Preview image data
        B->>C: Store in cache (design_id + params_crc32 -> preview_url)
        B->>DB: Update design (preview_image_url, params_crc32)
        B-->>F: Preview URL (new)
    end
    
    F-->>U: Display updated preview
```

### 3.2 Asynchronous Preview (for complex designs)

```mermaid
sequenceDiagram
    participant U as User
    participant F as Frontend
    participant B as Backend API
    participant Q as Queue System
    participant R as Render Worker
    participant DB as Database
    participant WS as WebSocket

    U->>F: Modify complex design parameters
    F->>B: POST /designs/{id}/preview-async
    
    B->>DB: Update design (status: queued, params)
    B->>Q: Queue preview render job
    B-->>F: Job queued (status: queued)
    
    F->>WS: Subscribe to design updates
    F-->>U: Show "Generating preview..." state
    
    Q->>R: Process preview render
    R->>DB: Update design (status: rendering)
    R->>WS: Notify status change (rendering)
    WS-->>F: Status update
    F-->>U: Update progress indicator
    
    R->>R: Generate preview image
    
    alt Render Success
        R->>DB: Update design (status: ready, preview_image_url)
        R->>WS: Notify completion with preview URL
        WS-->>F: Preview ready with URL
        F-->>U: Display new preview
    else Render Failure
        R->>DB: Update design (status: failed)
        R->>WS: Notify failure
        WS-->>F: Preview generation failed
        F-->>U: Show error message
    end
```

## 4. Reorder Functionality

```mermaid
sequenceDiagram
    participant U as User
    participant F as Frontend
    participant B as Backend API
    participant DB as Database

    U->>F: Click "Reorder" on past order
    F->>B: POST /orders/{id}/reorder
    
    B->>DB: Fetch original order details
    DB-->>B: Order with design_id
    
    B->>DB: Fetch original design
    DB-->>B: Original design data
    
    B->>DB: Create new design (duplicate)
    note over B,DB: Copy all design params<br/>Set original_design_id = original.id<br/>Reset status to 'draft'<br/>Clear image URLs
    DB-->>B: New design created
    
    B->>DB: Create new order (pending)
    note over B,DB: Use current pricing<br/>Recalculate shipping<br/>Apply current promotions
    DB-->>B: New order created
    
    B-->>F: New order details
    F-->>U: Redirect to checkout with duplicated design
    
    note over U,DB: User can modify the duplicated design<br/>before proceeding to payment
    
    U->>F: Optionally modify design
    F->>B: PUT /designs/{new_design_id}
    B->>DB: Update design parameters
    B-->>F: Design updated
    
    U->>F: Proceed to checkout
    note over U,DB: Follow normal checkout flow<br/>from step 1 diagram above
```

## Notes

### Design Status Flow
- `draft` → `queued` → `rendering` → `ready` | `failed`
- Reordered designs start as `draft` to allow modifications

### Order Status Flow
- `pending` → `confirmed` → `processing` → `shipped` → `delivered`
- Cancellation possible: `pending|confirmed` → `cancelled`
- Refund possible: `confirmed|processing|shipped|delivered` → `refunded`

### Render Job Retry Logic
- Maximum 3 attempts per render job
- Exponential backoff: 1min, 5min, 15min
- Different failure_reason categories for debugging:
  - `template_error`: Template data issues
  - `params_invalid`: User parameter validation failed
  - `render_timeout`: Rendering process timeout
  - `storage_error`: File upload/storage issues
  - `system_error`: Unexpected system errors

### Idempotency
- All render jobs use idempotency_key (design_id + params_crc32 + attempt)
- Stripe webhooks deduplicated using stripe_event_id
- Preview cache based on design_id + params_crc32

### Free Shipping Logic
```
subtotal_after_discount = subtotal - discount_total
shipping_fee = (subtotal_after_discount >= 8000) ? 0 : standard_shipping_fee
amount = subtotal_after_discount + shipping_fee
```