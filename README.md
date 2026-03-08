# Stripe Autohitter API

Stripe Checkout 3DS authentication bypass API.

## Endpoint

`GET /` or `POST /`

## Parameters

- `url` - Stripe checkout URL (with hash)
- `card` - Card in format: `cc|mm|yy|cvv`

## Example

```bash
curl "https://your-app.railway.app/?url=CHECKOUT_URL&card=4242424242424242|12|28|123"
```

## Response

```json
{
  "status": "charge|live|dead",
  "msg": "Response message",
  "merchant": "Merchant Name",
  "price": "USD 10.00",
  "product": "Product Name"
}
```

## Deploy to Railway

1. Push this directory to GitHub
2. Connect to Railway
3. Deploy automatically

Or use Railway CLI:
```bash
railway login
railway init
railway up
```
