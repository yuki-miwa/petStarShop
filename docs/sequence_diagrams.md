# petStarShop Sequence Diagrams

This document contains Mermaid sequence diagrams for the main workflows in the petStarShop EC site.

## 1. Checkout Flow

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant API
    participant DB
    participant Stripe
    participant Queue

    User->>Frontend: Complete design customization
    Frontend->>API: POST /designs/{id}/finalize
    API->>DB: Update design status to 'queued'
    API->>Queue: Queue render job
    API-->>Frontend: Design finalized

    User->>Frontend: Click "Order Now"
    Frontend->>API: POST /orders
    Note over API: Calculate prices, shipping
    API->>DB: Create order (status: draft)
    API-->>Frontend: Order created

    User->>Frontend: Proceed to checkout
    Frontend->>API: POST /orders/{id}/checkout
    API->>Stripe: Create checkout session
    Stripe-->>API: Session details
    API->>DB: Update order with stripe_checkout_session_id
    API-->>Frontend: Redirect to Stripe

    User->>Stripe: Complete payment
    Stripe->>API: Webhook payment_intent.succeeded
    API->>DB: Check idempotency (stripe_event_log)
    alt First time processing
        API->>DB: Log stripe event
        API->>DB: Update order payment_status to 'succeeded'
        API->>DB: Update order status to 'paid'
        Note over API: Trigger fulfillment process
    else Already processed
        Note over API: Skip processing (idempotent)
    end
    Stripe-->>User: Payment confirmation
```

## 2. Final Render Flow

```mermaid
sequenceDiagram
    participant Queue as Render Queue
    participant Worker as Render Worker
    participant DB
    participant Storage as File Storage
    participant API

    Queue->>Worker: Process render job
    Worker->>DB: Update render_job status to 'processing'
    Worker->>DB: Set started_at timestamp
    
    alt Successful render
        Worker->>Worker: Generate high-res image
        Worker->>Storage: Upload final image
        Storage-->>Worker: Image URL
        Worker->>DB: Update design.final_image_url
        Worker->>DB: Update design.status to 'ready'
        Worker->>DB: Update render_job status to 'completed'
        Worker->>DB: Set completed_at timestamp
    else Render failure
        Worker->>DB: Update render_job status to 'failed'
        Worker->>DB: Set failure_reason
        Worker->>DB: Update design.status to 'failed'
        alt Retry available (attempt < max_attempts)
            Worker->>DB: Increment attempt count
            Worker->>Queue: Re-queue job with delay
        else Max attempts reached
            Note over Worker: Notify admin of permanent failure
        end
    end
```

## 3. Preview Sync/Async Flow

### Synchronous Preview (Real-time)

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant API
    participant Cache

    User->>Frontend: Modify design parameter
    Frontend->>API: POST /designs/{id}/preview
    Note over API: Quick preview generation (low-res)
    
    alt Cache hit
        API->>Cache: Check preview cache
        Cache-->>API: Cached preview URL
        API-->>Frontend: Preview URL (fast)
    else Cache miss
        API->>API: Generate preview (< 2s)
        API->>Cache: Store preview
        API-->>Frontend: Preview URL
    end
    
    Frontend->>User: Display updated preview
```

### Asynchronous Preview (High Quality)

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant API
    participant Queue
    participant Worker
    participant WebSocket

    User->>Frontend: Request high-quality preview
    Frontend->>API: POST /designs/{id}/preview?quality=high
    API->>Queue: Queue high-quality render
    API-->>Frontend: Job queued (job_id)
    
    Frontend->>WebSocket: Subscribe to job updates
    
    Queue->>Worker: Process high-quality preview
    Worker->>Worker: Generate detailed preview
    Worker->>API: Update job status
    API->>WebSocket: Broadcast job completion
    WebSocket->>Frontend: Preview ready notification
    
    Frontend->>API: GET /designs/{id}
    API-->>Frontend: Updated design with preview_image_url
    Frontend->>User: Display high-quality preview
```

## 4. Reorder Flow

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant API
    participant DB

    User->>Frontend: Click "Reorder" on past order
    Frontend->>API: POST /orders/{order_id}/reorder
    
    API->>DB: Get original order details
    DB-->>API: Order with design_id
    
    API->>DB: Get original design
    DB-->>API: Design details
    
    API->>DB: Create new design (copy of original)
    Note over API: Set original_design_id reference
    DB-->>API: New design_id
    
    API->>DB: Create new order
    Note over API: Recalculate current pricing
    DB-->>API: New order_id
    
    API-->>Frontend: New order created
    Frontend->>User: Redirect to checkout/customization
    
    Note over User,DB: User can modify design before ordering
    Note over User,DB: Or proceed directly to checkout
```

## 5. CSV Export Job Flow

```mermaid
sequenceDiagram
    participant Admin
    participant AdminUI
    participant API
    participant DB
    participant Queue
    participant Worker
    participant Storage

    Admin->>AdminUI: Request data export
    AdminUI->>API: POST /admin/exports
    Note over API: Create export job
    API->>DB: Insert export_job record
    API->>Queue: Queue export job
    API-->>AdminUI: Job created (job_id)

    Queue->>Worker: Process export job
    Worker->>DB: Update status to 'processing'
    Worker->>DB: Set started_at timestamp
    
    loop Process data in batches
        Worker->>DB: Query data batch
        Worker->>Worker: Format as CSV
        Worker->>DB: Update processed_records count
    end
    
    Worker->>Storage: Upload CSV file
    Storage-->>Worker: File URL
    
    Worker->>DB: Update export_job
    Note over Worker: Set file_url, status='completed', completed_at
    
    AdminUI->>API: Poll job status (or WebSocket)
    API->>DB: Get job details
    API-->>AdminUI: Job completed with file_url
    
    Admin->>AdminUI: Download CSV
    AdminUI->>Storage: Download file
    Storage-->>Admin: CSV file
```

## 6. Order Status Update Flow

```mermaid
sequenceDiagram
    participant Admin
    participant AdminUI
    participant API
    participant DB
    participant Email
    participant User

    Admin->>AdminUI: Update order status
    AdminUI->>API: PUT /admin/orders/{id}
    
    API->>DB: Get current order
    API->>DB: Validate status transition
    
    alt Valid transition
        API->>DB: Update order status
        API->>DB: Log admin action
        
        alt Status = 'shipped'
            API->>DB: Set shipped_at timestamp
            API->>Email: Send shipping notification
            Email->>User: Shipping confirmation email
        else Status = 'delivered'
            API->>DB: Set delivered_at timestamp
            API->>Email: Send delivery confirmation
            Email->>User: Delivery confirmation email
        end
        
        API-->>AdminUI: Status updated successfully
    else Invalid transition
        API-->>AdminUI: Error: Invalid status transition
    end
```

## Notes

### Error Handling
- All API endpoints include proper error responses with status codes
- Database constraints prevent invalid data states
- Retry mechanisms for temporary failures (network, external services)
- Idempotency keys for critical operations (payments, renders)

### Performance Considerations
- Design parameter changes use CRC32 hash for quick comparison
- JSONB indexes for efficient querying of flexible data
- Background job processing for time-consuming operations
- Caching for frequently accessed previews

### Security
- Admin actions are logged for audit trail
- Rate limiting counters for API protection
- Stripe webhook verification for payment security
- UUID primary keys to prevent enumeration attacks