import { FormEvent, useRef, useState } from "react";
import ScanChecklist, { useScanChecklist } from "@/Components/ScanChecklist";

type ReturnRecord = {
  id: number; status: "pending" | "passed" | "damaged";
  shipment: { courier: string; tracking_id: string; order: {
    id: number; status: string; customer: { name: string; phone: string; address: string };
    items: { id: number; quantity: number; product: { id: number; sku: string; name: string; label?: string; barcode: string | null } }[];
  }};
};
// api must be your authenticated JSON client. It must throw on non-2xx responses.
// Keeping authentication outside this component supports same-origin sessions or a Next.js BFF.
type Props = { api: <T>(path: string, body: unknown) => Promise<T>; courier?: string; onDone?: () => void };
export default function CourierReturnPanel({ api, courier, onDone }: Props) {
  const [tracking, setTracking] = useState("");
  const [record, setRecord] = useState<ReturnRecord | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const lock = useRef(false);
  const input = useRef<HTMLInputElement>(null);
  async function run(action: () => Promise<void>) {
    if (lock.current) return;
    lock.current = true; setBusy(true); setError(""); setMessage("");
    try { await action(); }
    catch (e) { setError(e instanceof Error ? e.message : "Request failed. Please retry."); }
    finally { lock.current = false; setBusy(false); requestAnimationFrame(() => input.current?.focus()); }
  }
  async function scan(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const id = tracking.trim();
    if (!id) return;
    await run(async () => {
      setRecord(null); check.setScanned({});
      const result = await api<ReturnRecord>("/returns/scan", { courier_tracking_id: id, ...(courier ? { courier } : {}) });
      setRecord(result); setTracking(""); onDone?.();
      setMessage(result.status === "pending" ? "Return received. Inspect every item before approving." : "This return has already been reviewed.");
    });
  }
  async function review(decision: "QC_PASSED" | "DAMAGED") {
    if (!record || record.status !== "pending") return;
    const id = record.id;
    await run(async () => {
      const result = await api<ReturnRecord>(`/returns/${id}/qc`, { decision, scans: check.scans });
      setRecord(result);
      onDone?.();
      setMessage(result.status === "passed" ? "QC passed. Products restored to available stock." : "Marked damaged. Products were not added to available stock.");
    });
  }
  const order = record?.shipment.order;
  // Every returned piece must be scanned before QC (catches missing or swapped items in the parcel).
  const checkItems = (order?.items ?? []).map(i => ({ product_id: i.product.id, label: i.product.label ?? i.product.name, sku: i.product.sku, barcode: i.product.barcode, quantity: i.quantity }));
  const check = useScanChecklist(checkItems);
  return (
    <section className="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm" aria-busy={busy}>
      <header><h2 className="text-lg font-semibold text-slate-900">Scan returned parcel</h2>
        <p className="mt-1 text-sm text-slate-600">Scan the courier tracking barcode (USB scanner types it and presses Enter). The whole shipment is processed.</p>
      </header>
      <form onSubmit={scan} className="space-y-2">
        <label htmlFor="tracking-id" className="block text-sm font-medium text-slate-700">Courier tracking ID</label>
        <div className="flex gap-3">
          <input id="tracking-id" ref={input} autoFocus autoComplete="off" value={tracking}
            onChange={e => { setTracking(e.target.value); setRecord(null); setMessage(""); setError(""); }}
            disabled={busy} maxLength={120} placeholder="Scan or type tracking ID" required
            className="min-w-0 flex-1 rounded-lg border border-slate-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-slate-100" />
          <button disabled={busy || !tracking.trim()} className="rounded-lg bg-brand-600 px-5 font-semibold text-white disabled:opacity-50">{busy ? "Working…" : "Find"}</button>
        </div>
      </form>
      {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-800">{error}</p>}
      {message && <p role="status" className="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">{message}</p>}
      {record && order && <div className="space-y-4">
        <div className="flex flex-wrap justify-between gap-3 rounded-xl bg-slate-50 p-4">
          <div><h2 className="font-bold">Order #{order.id}</h2><p className="text-sm">{order.customer.name} · {order.customer.phone}</p>
            <p className="break-all text-sm text-slate-600">{record.shipment.courier} / {record.shipment.tracking_id}</p></div>
          <span className="self-start rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold uppercase">{record.status}</span>
        </div>
        <div className="overflow-x-auto"><table className="w-full text-left text-sm">
          <thead className="border-b text-slate-500"><tr><th className="py-3">Product</th><th>SKU</th><th className="text-right">Qty</th></tr></thead>
          <tbody>{order.items.map(i => <tr key={i.id} className="border-b border-slate-100"><td className="py-3 font-medium">{i.product.label ?? i.product.name}</td><td>{i.product.sku}</td><td className="text-right">{i.quantity}</td></tr>)}</tbody>
        </table></div>
        {record.status === "pending" && <div className="space-y-2">
          <p className="text-sm font-medium text-slate-700">Scan each returned item</p>
          <ScanChecklist key={record.id} items={checkItems} state={check} autoFocus={false} />
        </div>}
        <div className="flex flex-wrap gap-3">
          <button type="button" onClick={() => review("QC_PASSED")} disabled={busy || record.status !== "pending" || !check.done}
            className="rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-40">Approve Return (QC Pass)</button>
          <button type="button" onClick={() => review("DAMAGED")} disabled={busy || record.status !== "pending" || !check.done}
            className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 font-semibold text-red-800 disabled:opacity-40">Damaged / Wastage</button>
        </div>
      </div>}
    </section>
  );
}
