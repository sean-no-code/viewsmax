import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Copy } from "lucide-react";
import { toast } from "sonner";
import { useAuth } from "@/hooks/useAuth";

export function ScriptModal() {
    const { user } = useAuth();

    // Use VITE_API_BASE_URL (or a separate TRACKING_URL if defined)
    const trackingUrl = import.meta.env.VITE_API_BASE_URL || 'https://api.viewsmax.ai';
    // Ensure we point to the JS file if it serves one, OR just the base for now.
    // The plan says: <script src="{{ config('app.tracker_url') }}" defer></script>
    // In our case, we haven't built the JS file yet, but let's assume it will be at /tracker.js
    const scriptSrc = `${trackingUrl}/tracker.js`;

    const scriptCode = `<!-- ViewsMax Universal Tracking -->
<meta name="viewsmax-user" content="${user?.public_id || 'YOUR_PUBLIC_ID'}">
<script src="${scriptSrc}" defer></script>
<!-- End ViewsMax -->`;

    const handleCopy = () => {
        navigator.clipboard.writeText(scriptCode);
        toast.success("Script copied to clipboard!");
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline">Get Script Snippet</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Install Tracking Script</DialogTitle>
                    <DialogDescription>
                        Place this code in the <code>&lt;head&gt;</code> of every page on your website.
                    </DialogDescription>
                </DialogHeader>
                <div className="flex items-center space-x-2">
                    <div className="grid flex-1 gap-2">
                        <pre className="p-4 rounded bg-slate-950 text-slate-50 overflow-x-auto text-sm">
                            <code>{scriptCode}</code>
                        </pre>
                    </div>
                </div>
                <div className="flex justify-end">
                    <Button type="button" size="sm" className="px-3" onClick={handleCopy}>
                        <span className="sr-only">Copy</span>
                        <Copy className="h-4 w-4 mr-2" />
                        Copy Code
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
