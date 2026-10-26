"""Stand-in PayRam for the WooCommerce plugin tests (tests/e2e/run.sh).

Mirrors payram-core's shapes (internal/api/handlers/payment_handler.go,
internal/api/params/payment_request_response.go):
  POST /api/v1/payment (API-Key)  -> {url, reference_id, host}
  GET  /api/v1/payment/reference/{ref} -> params.PaymentRequestResponse
       (decimal amounts are JSON strings, as shopspring/decimal marshals them)
       unknown ref -> 401 {"error": {...}} (PaymentRequestMiddleware)
Test control (not part of PayRam):
  POST /_control/set   {reference_id, ...fields}  create/patch a record
  POST /_control/raw   {reference_id, status, body} serve a raw response
  POST /_control/create_mode {omit_reference: bool}
  GET  /_control/hits/{ref} -> number of verify GETs for ref
"""
import json
import uuid
from http.server import BaseHTTPRequestHandler, HTTPServer

API_KEY = "test-key"
RECORDS = {}
RAW = {}
HITS = {}
CREATE_MODE = {"omit_reference": False}


class H(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        print(self.command, self.path, flush=True)

    def _send(self, code, obj):
        b = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def _body(self):
        n = int(self.headers.get("Content-Length", 0))
        return json.loads(self.rfile.read(n) or b"{}")

    def do_POST(self):
        body = self._body()
        if self.path == "/api/v1/payment":
            if self.headers.get("API-Key") != API_KEY:
                return self._send(401, {"error": {"code": "UNAUTHORISED", "message": "Unauthorised access"}})
            ref = str(uuid.uuid4())
            RECORDS[ref] = {
                "invoiceID": body.get("invoiceID", ""),
                "customerID": body.get("customerID", ""),
                "amountInUSD": str(body.get("amountInUSD")),
                "filledAmount": None,
                "filledAmountInUSD": None,
                "sponsoredAmount": "0",
                "sponsoredAmountInUSD": "0",
                "paymentState": "OPEN",
                "referenceID": ref,
                "merchantName": "stand-in",
            }
            print("CREATE", ref, json.dumps(body), flush=True)
            if CREATE_MODE["omit_reference"]:
                return self._send(200, {"url": "https://pay.example.invalid/payments?reference_id=" + ref, "host": "pay.example.invalid"})
            return self._send(200, {"url": "https://pay.example.invalid/payments?reference_id=" + ref, "reference_id": ref, "host": "pay.example.invalid"})
        if self.path == "/_control/set":
            ref = body.pop("reference_id")
            RECORDS.setdefault(ref, {"referenceID": ref, "sponsoredAmount": "0", "sponsoredAmountInUSD": "0"})
            for k, v in body.items():
                if v == "__delete__":
                    RECORDS[ref].pop(k, None)
                else:
                    RECORDS[ref][k] = v
            return self._send(200, RECORDS[ref])
        if self.path == "/_control/create_mode":
            CREATE_MODE.update(body)
            return self._send(200, CREATE_MODE)
        if self.path == "/_control/raw":
            RAW[body["reference_id"]] = (int(body["status"]), body["body"])
            return self._send(200, {"ok": True})
        self._send(404, {"error": {"code": "NOT_FOUND", "message": "Record not found"}})

    def do_GET(self):
        prefix = "/api/v1/payment/reference/"
        if self.path.startswith("/_control/hits/"):
            return self._send(200, {"hits": HITS.get(self.path[len("/_control/hits/"):], 0)})
        if self.path.startswith(prefix):
            ref = self.path[len(prefix):]
            HITS[ref] = HITS.get(ref, 0) + 1
            if ref in RAW:
                code, obj = RAW[ref]
                return self._send(code, obj)
            if ref not in RECORDS:
                return self._send(401, {"error": {"code": "UNAUTHORISED", "message": "Unauthorised access"}})
            return self._send(200, RECORDS[ref])
        self._send(404, {"error": {"code": "NOT_FOUND", "message": "Record not found"}})


HTTPServer(("0.0.0.0", 8080), H).serve_forever()
