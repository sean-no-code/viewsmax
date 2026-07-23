import { useEffect, useState } from "react";
import { viewsMaxApi, type Brand } from "@/lib/api-service";

/**
 * The user's brands — named groups of connected accounts the composer can
 * select in one click. Cached module-wide (same pattern as
 * useAccountDirectory) so the Connections page and the composer always see
 * the same list; call refreshBrands() after any brand CRUD.
 */
export interface BrandsState {
  loading: boolean;
  brands: Brand[];
}

let cached: BrandsState | null = null;
let inflight: Promise<BrandsState> | null = null;
const listeners = new Set<(s: BrandsState) => void>();

async function fetchBrands(): Promise<BrandsState> {
  const result = await viewsMaxApi.getBrands();
  return { loading: false, brands: result.success && result.data ? result.data : [] };
}

function load(): Promise<BrandsState> {
  if (cached) return Promise.resolve(cached);
  if (!inflight) {
    inflight = fetchBrands().then((state) => {
      cached = state;
      inflight = null;
      listeners.forEach((fn) => fn(state));
      return state;
    });
  }
  return inflight;
}

/** Drop the cache (after create/update/delete) and refetch for all consumers. */
export function refreshBrands(): void {
  cached = null;
  inflight = null;
  void load();
}

const EMPTY: BrandsState = { loading: true, brands: [] };

export function useBrands(): BrandsState {
  const [state, setState] = useState<BrandsState>(cached ?? EMPTY);

  useEffect(() => {
    let mounted = true;
    const onUpdate = (s: BrandsState) => { if (mounted) setState(s); };
    listeners.add(onUpdate);
    void load().then(onUpdate);
    return () => {
      mounted = false;
      listeners.delete(onUpdate);
    };
  }, []);

  return state;
}
