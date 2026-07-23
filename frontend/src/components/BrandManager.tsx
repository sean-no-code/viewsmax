import { useEffect, useMemo, useState } from "react";
import { Loader2, Pencil, Plus, Trash2, AlertTriangle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  viewsMaxApi,
  accountNeedsReconnect,
  type Brand,
  type Connection,
  type SocialAccount,
} from "@/lib/api-service";
import { PAvatar, PMAP } from "@/components/post/composer";
import { useBrands, refreshBrands } from "@/components/post/useBrands";
import { toast } from "sonner";

/**
 * Brands — named groups of connected accounts. A brand can mix accounts from
 * both stores (social + legacy YouTube/TikTok); the composer selects a whole
 * brand in one click. Member rows are keyed "social:{id}" / "legacy:{id}"
 * because the two stores have independent id spaces.
 */

type MemberKey = string;

const socialKey = (id: number): MemberKey => `social:${id}`;
const legacyKey = (id: number): MemberKey => `legacy:${id}`;

interface MemberRow {
  key: MemberKey;
  platform: string;
  name: string;
  avatarUrl: string | null;
  disabled: boolean; // expired social accounts can't post — shown but not selectable
}

const BrandManager = () => {
  const { loading: brandsLoading, brands } = useBrands();
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [connections, setConnections] = useState<Connection[]>([]);
  const [accountsLoading, setAccountsLoading] = useState(true);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<Brand | null>(null);
  const [name, setName] = useState("");
  const [selected, setSelected] = useState<Set<MemberKey>>(new Set());
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  useEffect(() => {
    (async () => {
      const [social, legacy] = await Promise.all([
        viewsMaxApi.getSocialAccounts(),
        viewsMaxApi.getConnections(),
      ]);
      if (social.success && social.data) setAccounts(social.data);
      if (legacy.success && legacy.data) setConnections(legacy.data);
      setAccountsLoading(false);
    })();
  }, []);

  const memberRows = useMemo<MemberRow[]>(() => {
    const social = accounts.map((a) => ({
      key: socialKey(a.id),
      platform: a.platform,
      name: a.name || (a.username ? `@${a.username}` : `Account ${a.id}`),
      avatarUrl: a.avatar_url || null,
      disabled: accountNeedsReconnect(a),
    }));
    const legacy = connections.map((c) => ({
      key: legacyKey(c.id),
      platform: c.provider,
      name: c.account_name || c.provider,
      avatarUrl: c.avatar_url || null,
      disabled: false, // the legacy store has no token status
    }));
    return [...social, ...legacy].sort((a, b) => a.platform.localeCompare(b.platform));
  }, [accounts, connections]);

  const openCreate = () => {
    setEditing(null);
    setName("");
    setSelected(new Set());
    setDialogOpen(true);
  };

  const openEdit = (brand: Brand) => {
    setEditing(brand);
    setName(brand.name);
    setSelected(new Set([
      ...brand.social_accounts.map((a) => socialKey(a.id)),
      ...brand.connections.map((c) => legacyKey(c.id)),
    ]));
    setDialogOpen(true);
  };

  const toggleMember = (key: MemberKey) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const handleSave = async () => {
    const trimmed = name.trim();
    if (!trimmed) {
      toast.error("Give the brand a name.");
      return;
    }
    setSaving(true);
    const payload = {
      name: trimmed,
      social_account_ids: [...selected].filter((k) => k.startsWith("social:")).map((k) => Number(k.slice(7))),
      connection_ids: [...selected].filter((k) => k.startsWith("legacy:")).map((k) => Number(k.slice(7))),
    };
    const result = editing
      ? await viewsMaxApi.updateBrand(editing.id, payload)
      : await viewsMaxApi.createBrand(payload);
    setSaving(false);
    if (!result.success) {
      toast.error(result.error || "Failed to save brand.");
      return;
    }
    refreshBrands();
    setDialogOpen(false);
    toast.success(editing ? "Brand updated." : "Brand created.");
  };

  const handleDelete = async (brand: Brand) => {
    if (!window.confirm(`Delete the brand "${brand.name}"? Its accounts stay connected.`)) return;
    setDeletingId(brand.id);
    const result = await viewsMaxApi.deleteBrand(brand.id);
    setDeletingId(null);
    if (!result.success) {
      toast.error(result.error || "Failed to delete brand.");
      return;
    }
    refreshBrands();
    toast.success("Brand deleted.");
  };

  /** Resolve a brand member's display identity from the live account lists. */
  const brandAvatars = (brand: Brand) => [
    ...brand.social_accounts.map((a) => ({
      key: socialKey(a.id),
      platform: a.platform,
      avatarUrl: a.avatar_url || null,
      stale: accountNeedsReconnect(a),
      label: a.name || (a.username ? `@${a.username}` : a.platform),
    })),
    ...brand.connections.map((c) => ({
      key: legacyKey(c.id),
      platform: c.provider,
      avatarUrl: c.avatar_url || null,
      stale: false,
      label: c.account_name || c.provider,
    })),
  ];

  if (brandsLoading) {
    return (
      <div className="flex items-center justify-center py-6">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div>
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          Group accounts into brands, then pick a brand in Create Post to select all of its accounts at once.
        </p>
        <Button size="sm" onClick={openCreate}>
          <Plus className="h-4 w-4" />
          <span className="ml-1">New brand</span>
        </Button>
      </div>

      {brands.length > 0 && (
        <div className="mt-3 space-y-2">
          {brands.map((brand) => {
            const members = brandAvatars(brand);
            return (
              <div key={brand.id} className="flex items-center justify-between rounded-lg border p-3">
                <div className="flex min-w-0 items-center gap-3">
                  <div className="min-w-0">
                    <p className="truncate font-medium">{brand.name}</p>
                    <p className="text-sm text-muted-foreground">
                      {members.length === 0 ? "No accounts yet" : `${members.length} account${members.length === 1 ? "" : "s"}`}
                    </p>
                  </div>
                  <div className="flex items-center -space-x-2">
                    {members.slice(0, 6).map((m) => (
                      <span key={m.key} title={m.label} style={{ opacity: m.stale ? 0.45 : 1 }}>
                        <PAvatar id={m.platform} size={26} avatarUrl={m.avatarUrl} />
                      </span>
                    ))}
                    {members.length > 6 && (
                      <span className="ml-3 text-xs text-muted-foreground">+{members.length - 6}</span>
                    )}
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Button variant="outline" size="sm" onClick={() => openEdit(brand)}>
                    <Pencil className="h-4 w-4" />
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={deletingId === brand.id}
                    onClick={() => handleDelete(brand)}
                  >
                    {deletingId === brand.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                  </Button>
                </div>
              </div>
            );
          })}
        </div>
      )}

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editing ? "Edit brand" : "New brand"}</DialogTitle>
            <DialogDescription>
              Name the brand and choose which connected accounts belong to it.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <Input
              placeholder="Brand name"
              value={name}
              maxLength={80}
              onChange={(e) => setName(e.target.value)}
              autoFocus
            />
            {accountsLoading ? (
              <div className="flex items-center justify-center py-4">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              </div>
            ) : memberRows.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No connected accounts yet — connect some platforms above first.
              </p>
            ) : (
              <div className="max-h-64 space-y-1 overflow-y-auto pr-1">
                {memberRows.map((row) => {
                  // An expired account can't be ADDED, but one already in the
                  // brand must stay removable.
                  const locked = row.disabled && !selected.has(row.key);
                  return (
                  <label
                    key={row.key}
                    className={`flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-muted/40 ${locked ? "cursor-not-allowed opacity-60" : ""}`}
                  >
                    <Checkbox
                      checked={selected.has(row.key)}
                      disabled={locked}
                      onCheckedChange={() => toggleMember(row.key)}
                    />
                    <PAvatar id={row.platform} size={26} avatarUrl={row.avatarUrl} />
                    <span className="min-w-0 flex-1 truncate text-sm">{row.name}</span>
                    <span className="text-xs text-muted-foreground">{PMAP[row.platform]?.name ?? row.platform}</span>
                    {row.disabled && (
                      <span className="flex items-center gap-1 text-xs text-amber-600">
                        <AlertTriangle className="h-3 w-3" /> expired
                      </span>
                    )}
                  </label>
                  );
                })}
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogOpen(false)}>Cancel</Button>
            <Button disabled={saving} onClick={handleSave}>
              {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : editing ? "Save" : "Create"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
};

export default BrandManager;
