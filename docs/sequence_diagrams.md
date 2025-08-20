# Sequence Diagrams - petStarShop EC Site

This document contains sequence diagrams for the main workflows in the petStarShop EC site.

## 1. Checkout Process

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant Laravel
    participant Stripe
    participant Database
    participant RenderWorker

    User->>Frontend: Review design and click "Checkout"
    Frontend->>Laravel: POST /api/orders/checkout
    Note right of Laravel: Validate design is ready
    Laravel->>Database: Check design status
    Database-->>Laravel: Design status: ready
    
    Laravel->>Database: Calculate pricing
    Note right of Laravel: base_unit_price * quantity<br/>Apply discount if any<br/>Free shipping if >= 8000 yen
    Database-->>Laravel: Price calculation complete
    
    Laravel->>Database: Create order (status: pending)
    Database-->>Laravel: Order created
    
    Laravel->>Stripe: Create PaymentIntent
    Stripe-->>Laravel: PaymentIntent with client_secret
    
    Laravel-->>Frontend: Order created with payment_intent
    Frontend-->>User: Show checkout form
    
    User->>Frontend: Enter payment details
    Frontend->>Stripe: Confirm payment
    Stripe-->>Frontend: Payment successful
    
    Frontend->>Laravel: POST /api/orders/{id}/confirm
    Laravel->>Database: Update order (status: confirmed, payment_status: completed)
    Database-->>Laravel: Order updated
    
    Laravel->>RenderWorker: Queue final render job
    Note right of RenderWorker: High-quality render for printing
    
    Laravel-->>Frontend: Order confirmed
    Frontend-->>User: Show order confirmation
```

## 2. Final Render Workflow

```mermaid
sequenceDiagram
    participant OrderSystem
    participant RenderQueue
    participant RenderWorker
    participant Database
    participant FileStorage

    OrderSystem->>RenderQueue: Queue render job
    Note right of OrderSystem: After order confirmation
    
    RenderQueue->>RenderWorker: Process render job
    RenderWorker->>Database: Get design params
    Database-->>RenderWorker: Design parameters
    
    RenderWorker->>RenderWorker: Generate high-quality image
    Note right of RenderWorker: Apply design params to template<br/>Render at print resolution
    
    alt Render successful
        RenderWorker->>FileStorage: Upload rendered image
        FileStorage-->>RenderWorker: Image URL
        
        RenderWorker->>Database: Update render_job (status: completed, output_image_url)
        RenderWorker->>Database: Update order (status: processing)
        Database-->>RenderWorker: Status updated
        
        RenderWorker->>OrderSystem: Notify render complete
    else Render failed
        RenderWorker->>Database: Update render_job (status: failed, failure_reason)
        Database-->>RenderWorker: Status updated
        
        RenderWorker->>OrderSystem: Notify render failed
        Note right of OrderSystem: May retry or escalate to admin
    end
```

## 3. Preview Sync/Async Operations

### 3.1 Synchronous Preview (for simple designs)

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant Laravel
    participant Database

    User->>Frontend: Update design parameters
    Frontend->>Laravel: PUT /api/designs/{id}
    Laravel->>Database: Update design params
    Database-->>Laravel: Design updated
    
    Laravel->>Laravel: Generate preview (sync)
    Note right of Laravel: Quick preview render<br/>Lower resolution
    
    Laravel->>Database: Update design (preview_image_url, status: ready)
    Database-->>Laravel: Design updated
    
    Laravel-->>Frontend: Design updated with preview
    Frontend-->>User: Show updated preview
```

### 3.2 Asynchronous Preview (for complex designs)

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant Laravel
    participant Database
    participant RenderQueue
    participant RenderWorker

    User->>Frontend: Update complex design parameters
    Frontend->>Laravel: PUT /api/designs/{id}
    Laravel->>Database: Update design params
    Database-->>Laravel: Design updated
    
    Laravel->>Database: Update design (status: queued)
    Laravel->>RenderQueue: Queue preview render job
    RenderQueue-->>Laravel: Job queued
    
    Laravel-->>Frontend: Design queued for rendering
    Frontend-->>User: Show "Rendering..." status
    
    RenderQueue->>RenderWorker: Process preview render
    RenderWorker->>Database: Get design params
    Database-->>RenderWorker: Design parameters
    
    RenderWorker->>RenderWorker: Generate preview
    
    alt Render successful
        RenderWorker->>Database: Update design (status: ready, preview_image_url)
        Database-->>RenderWorker: Design updated
        
        RenderWorker->>Frontend: WebSocket notification
        Frontend-->>User: Show updated preview
    else Render failed
        RenderWorker->>Database: Update design (status: failed)
        Database-->>RenderWorker: Design updated
        
        RenderWorker->>Frontend: WebSocket notification
        Frontend-->>User: Show error message
    end
```

## 4. Reorder Functionality

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant Laravel
    participant Database

    User->>Frontend: Click "Reorder" on past order
    Frontend->>Laravel: POST /api/orders/{id}/reorder
    
    Laravel->>Database: Get original order details
    Database-->>Laravel: Order with design_id
    
    Laravel->>Database: Get original design
    Database-->>Laravel: Original design data
    
    Laravel->>Database: Create new design (copy of original)
    Note right of Laravel: Set original_design_id<br/>Copy all params<br/>Reset status to draft
    Database-->>Laravel: New design created
    
    Laravel->>Database: Check if template still active
    Database-->>Laravel: Template status
    
    alt Template active
        Laravel-->>Frontend: New design created for editing
        Frontend-->>User: Redirect to design editor
        Note right of User: User can modify before ordering
    else Template inactive
        Laravel-->>Frontend: Template no longer available
        Frontend-->>User: Show error message
        Note right of User: Suggest similar templates
    end
    
    Note over User,Database: User proceeds with normal<br/>design → checkout → order flow
```

## 5. CSV Export Job Workflow

```mermaid
sequenceDiagram
    participant Admin
    participant AdminPanel
    participant Laravel
    participant Database
    participant ExportQueue
    participant ExportWorker
    participant FileStorage

    Admin->>AdminPanel: Request CSV export
    AdminPanel->>Laravel: POST /api/admin/exports
    Note right of Laravel: type: orders_csv/users_csv/designs_csv<br/>parameters: date_range, filters
    
    Laravel->>Database: Create export_job (status: queued)
    Database-->>Laravel: Export job created
    
    Laravel->>ExportQueue: Queue export job
    ExportQueue-->>Laravel: Job queued
    
    Laravel-->>AdminPanel: Export job queued
    AdminPanel-->>Admin: Show "Export in progress..."
    
    ExportQueue->>ExportWorker: Process export job
    ExportWorker->>Database: Update job (status: processing, started_at)
    
    ExportWorker->>Database: Query data based on parameters
    Database-->>ExportWorker: Data chunks
    
    loop For each data chunk
        ExportWorker->>ExportWorker: Generate CSV rows
        ExportWorker->>Database: Update progress_percentage
    end
    
    ExportWorker->>FileStorage: Upload CSV file
    FileStorage-->>ExportWorker: File URL
    
    ExportWorker->>Database: Update job (status: completed, file_url, completed_at)
    Database-->>ExportWorker: Job updated
    
    ExportWorker->>AdminPanel: WebSocket notification
    AdminPanel-->>Admin: Show download link
    
    Admin->>FileStorage: Download CSV file
    FileStorage-->>Admin: CSV file download
```

## 6. Stripe Webhook Processing

```mermaid
sequenceDiagram
    participant Stripe
    participant Laravel
    participant Database

    Stripe->>Laravel: POST /webhook/stripe
    Note right of Stripe: payment_intent.succeeded<br/>payment_intent.payment_failed<br/>etc.

    Laravel->>Laravel: Verify webhook signature
    
    alt Signature valid
        Laravel->>Database: Check stripe_event_log
        Database-->>Laravel: Check if event already processed
        
        alt Event not processed
            Laravel->>Database: Insert stripe_event_log (processed: false)
            Database-->>Laravel: Event logged
            
            Laravel->>Laravel: Process event based on type
            
            alt payment_intent.succeeded
                Laravel->>Database: Update order (payment_status: completed)
                Laravel->>Database: Trigger render job if needed
            else payment_intent.payment_failed
                Laravel->>Database: Update order (payment_status: failed)
            else charge.dispute.created
                Laravel->>Database: Update order (payment_status: disputed)
                Note right of Laravel: Alert admin for review
            end
            
            Laravel->>Database: Update stripe_event_log (processed: true, processed_at)
            Database-->>Laravel: Event marked as processed
            
            Laravel-->>Stripe: 200 OK
        else Event already processed
            Laravel-->>Stripe: 200 OK (idempotent)
        end
    else Signature invalid
        Laravel-->>Stripe: 400 Bad Request
    end
```

## Notes

- All sequence diagrams assume proper error handling and logging
- WebSocket notifications are optional and can be replaced with polling
- File storage can be AWS S3, Google Cloud Storage, or local storage
- Render workers should implement retry logic with exponential backoff
- All monetary amounts are stored as integers (yen cents) to avoid floating point issues
- Rate limiting should be applied to all public-facing endpoints