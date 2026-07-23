import { useState, useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Badge } from "@/components/ui/badge";
import { ScrollText, Wand2, Loader2, Edit, Trash2, Plus, FileText } from "lucide-react";
import { toast } from "sonner";
import { viewsMaxApi, Script, PaginatedResponse } from "@/lib/api-service";
import {
  Pagination,
  PaginationContent,
  PaginationEllipsis,
  PaginationItem,
  PaginationLink,
  PaginationNext,
  PaginationPrevious,
} from "@/components/ui/pagination";
import { useNavigate } from "react-router-dom";
import { cn } from "@/lib/utils";

const Scripts = () => {
  const [scripts, setScripts] = useState<Script[]>([]);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState<PaginatedResponse<Script> | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isGenerating, setIsGenerating] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [scriptToDelete, setScriptToDelete] = useState<string | null>(null);
  const navigate = useNavigate();

  useEffect(() => {
    loadScripts();
  }, [page]);

  const loadScripts = async () => {
    try {
      setIsLoading(true);
      const result = await viewsMaxApi.getScripts(page);
      
      if (result.success && result.data) {
        setScripts(result.data.data);
        setMeta(result.data);
      } else {
        toast.error(result.error || "Failed to load scripts");
      }
    } catch (error) {
      console.error("Error loading scripts:", error);
      toast.error("Failed to load scripts");
    } finally {
      setIsLoading(false);
    }
  };

  const handleScriptAction = async (scriptId: string) => {
    // Navigate to edit page using script ID
    navigate(`/dashboard/scripts/edit/${scriptId}`, { 
      state: { fromWandClick: true } 
    });
  };

  const handleDeleteClick = (scriptId: string) => {
    setScriptToDelete(scriptId);
    setDeleteDialogOpen(true);
  };

  const handleDeleteScript = async () => {
    if (!scriptToDelete) return;

    try {
      const result = await viewsMaxApi.deleteScript(scriptToDelete);
      
      if (result.success) {
        setScripts(scripts.filter(script => script.id.toString() !== scriptToDelete));
        toast.success("Script deleted successfully");
        setDeleteDialogOpen(false);
        setScriptToDelete(null);
      } else {
        toast.error(result.error || "Failed to delete script");
      }
    } catch (error) {
      console.error("Error deleting script:", error);
      toast.error("Failed to delete script");
    }
  };

  const getStatusDisplay = (status?: string) => {
    if (!status) return { text: 'Pending', color: 'bg-gray-100 text-gray-700' };
    switch (status) {
      case 'completed':
        return { text: 'Completed', color: 'bg-green-100 text-green-700' };
      case 'processing':
        return { text: 'Processing', color: 'bg-yellow-100 text-yellow-700' };
      case 'failed':
        return { text: 'Failed', color: 'bg-red-100 text-red-700' };
      default:
        return { text: 'Pending', color: 'bg-gray-100 text-gray-700' };
    }
  };

  if (isLoading) {
    return (
      <div className="space-y-6 max-w-6xl">
        <div className="flex items-center justify-center h-64">
          <div className="text-center">
            <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4" />
            <p className="text-muted-foreground">Loading scripts...</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-6xl">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Scripts</h1>
          <p className="text-muted-foreground">
            Create and manage your video scripts
          </p>
        </div>
        <Button 
          onClick={() => navigate('/dashboard/scripts/create')}
          className="gap-2"
        >
          <Plus className="w-4 h-4" />
          Create Script
        </Button>
      </div>

      {/* Scripts List */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Your Scripts</CardTitle>
          <CardDescription>
            Click on a script to edit or manage it
          </CardDescription>
        </CardHeader>
        <CardContent>
          {scripts.length === 0 ? (
            <div className="text-center py-12">
              <div className="w-16 h-16 rounded-full bg-muted flex items-center justify-center mx-auto mb-4">
                <ScrollText className="w-8 h-8 text-muted-foreground" />
              </div>
              <h3 className="font-medium mb-2">No scripts yet</h3>
              <p className="text-sm text-muted-foreground mb-6 max-w-sm mx-auto">
                Get started by creating your first script. Our AI-powered wizard will help you craft the perfect video script.
              </p>
              <Button onClick={() => navigate('/dashboard/scripts/create')}>
                <Wand2 className="w-4 h-4 mr-2" />
                Create Your First Script
              </Button>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Title</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Word Count</TableHead>
                  <TableHead className="text-right">Reading Time</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {scripts.map((script) => {
                  const status = getStatusDisplay(script.status);

                  return (
                    <TableRow 
                      key={script.id}
                      className="cursor-pointer hover:bg-muted/50"
                      onClick={() => handleScriptAction(script.id.toString())}
                    >
                      <TableCell className="font-medium">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                            <FileText className="w-4 h-4 text-primary" />
                          </div>
                          <span className="truncate max-w-[300px]">
                            {script.title || 'Untitled Script'}
                          </span>
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline" className={cn("text-xs", status.color)}>
                          {status.text}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {script.word_count !== undefined ? script.word_count.toLocaleString() : '-'}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {script.reading_time !== undefined ? `${script.reading_time} min` : '-'}
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex items-center justify-end gap-2" onClick={(e) => e.stopPropagation()}>
                          <TooltipProvider>
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <Button
                                  onClick={() => handleScriptAction(script.id.toString())}
                                  disabled={isGenerating}
                                  size="sm"
                                  variant="outline"
                                  className="h-8 w-8 p-0"
                                >
                                  {isGenerating ? (
                                    <Loader2 className="w-4 h-4 animate-spin" />
                                  ) : (
                                    <Edit className="w-4 h-4" />
                                  )}
                                </Button>
                              </TooltipTrigger>
                              <TooltipContent>
                                Edit script
                              </TooltipContent>
                            </Tooltip>
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <Button
                                  onClick={() => handleDeleteClick(script.id.toString())}
                                  disabled={isGenerating}
                                  size="sm"
                                  variant="destructive"
                                  className="h-8 w-8 p-0"
                                >
                                  <Trash2 className="w-4 h-4" />
                                </Button>
                              </TooltipTrigger>
                              <TooltipContent>
                                Delete script
                              </TooltipContent>
                            </Tooltip>
                          </TooltipProvider>
                        </div>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Pagination */}
      {
        meta && meta.last_page > 1 && (
          <Pagination>
            <PaginationContent>
              <PaginationItem>
                <PaginationPrevious
                  onClick={() => setPage(p => Math.max(1, p - 1))}
                  className={page === 1 ? "pointer-events-none opacity-50" : "cursor-pointer"}
                />
              </PaginationItem>

              {Array.from({ length: meta.last_page }, (_, i) => i + 1).map((pageNum) => {
                // Simple logic: render all if small, otherwise truncation needed. 
                // For now, let's limit to displaying 5 pages around current
                if (
                  pageNum === 1 ||
                  pageNum === meta.last_page ||
                  (pageNum >= page - 1 && pageNum <= page + 1)
                ) {
                  return (
                    <PaginationItem key={pageNum}>
                      <PaginationLink
                        isActive={pageNum === page}
                        onClick={() => setPage(pageNum)}
                        className="cursor-pointer"
                      >
                        {pageNum}
                      </PaginationLink>
                    </PaginationItem>
                  );
                }
                if (
                  (pageNum === page - 2 && page > 3) ||
                  (pageNum === page + 2 && page < meta.last_page - 2)
                ) {
                  return <PaginationItem key={pageNum}><PaginationEllipsis /></PaginationItem>;
                }
                return null;
              })}

              <PaginationItem>
                <PaginationNext
                  onClick={() => setPage(p => Math.min(meta.last_page, p + 1))}
                  className={page === meta.last_page ? "pointer-events-none opacity-50" : "cursor-pointer"}
                />
              </PaginationItem>
            </PaginationContent>
          </Pagination>
        )
      }

      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Are you sure?</AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. This will permanently delete the script.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteScript}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};

export default Scripts;
