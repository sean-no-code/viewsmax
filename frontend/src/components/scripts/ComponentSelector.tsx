import { useState, useEffect } from "react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Search, Plus, Check, Sparkles } from "lucide-react";
import { 
  viewsMaxApi,
  LibraryComponent
} from "@/lib/api-service";
import {
  componentTypeConfig,
  ComponentType 
} from "@/lib/scripts-static-data";
import { cn } from "@/lib/utils";

interface ComponentSelectorProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSelectComponent: (component: LibraryComponent) => void;
  selectedComponentIds: string[];
}

const ComponentSelector = ({
  open,
  onOpenChange,
  onSelectComponent,
  selectedComponentIds,
}: ComponentSelectorProps) => {
  const [components, setComponents] = useState<LibraryComponent[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [typeFilter, setTypeFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [expandedComponentId, setExpandedComponentId] = useState<string | null>(null);

  // Fetch components when dialog opens or filters change
  useEffect(() => {
    if (open) {
      fetchComponents();
    }
  }, [open, typeFilter, searchQuery]);

  const fetchComponents = async () => {
    setIsLoading(true);
    try {
      const result = await viewsMaxApi.getLibraryComponents();
      
      if (result.success && result.data) {
        let retrived = result.data;
        // Client side filtering for now until API supports it
        if (typeFilter !== 'all') {
          retrived = retrived.filter(c => c.type === typeFilter);
        }
        if (searchQuery) {
          const lower = searchQuery.toLowerCase();
          retrived = retrived.filter(c =>
            c.title.toLowerCase().includes(lower) ||
            c.body.toLowerCase().includes(lower) ||
            c.tags?.some(t => t.name.toLowerCase().includes(lower))
          );
        }
        setComponents(retrived);
      }
    } catch (error) {
      console.error("Error fetching components:", error);
    } finally {
      setIsLoading(false);
    }
  };

  const handleSelectComponent = (component: LibraryComponent) => {
    onSelectComponent(component);
    // Don't close the dialog, allow adding multiple components
  };

  const isComponentSelected = (componentId: string) => 
    selectedComponentIds.includes(componentId);

  const getTypeColor = (type: string) => {
    const colors: Record<string, string> = {
      hook: 'bg-orange-100 text-orange-700 border-orange-200',
      cta: 'bg-blue-100 text-blue-700 border-blue-200',
      outro: 'bg-purple-100 text-purple-700 border-purple-200',
      transition: 'bg-green-100 text-green-700 border-green-200',
      story: 'bg-pink-100 text-pink-700 border-pink-200',
    };
    return colors[type] || 'bg-gray-100 text-gray-700 border-gray-200';
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl max-h-[85vh] p-0 gap-0">
        <DialogHeader className="px-6 py-4 border-b">
          <DialogTitle className="flex items-center gap-2">
            <Sparkles className="w-5 h-5 text-primary" />
            Add a component to your script
          </DialogTitle>
        </DialogHeader>
        
        {/* Filters */}
        <div className="px-6 py-4 border-b bg-muted/30">
          <div className="flex gap-3">
            <Select value={typeFilter} onValueChange={setTypeFilter}>
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Filter by type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All types</SelectItem>
                {Object.entries(componentTypeConfig).map(([key, config]) => (
                  <SelectItem key={key} value={key}>
                    {config.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            
            <div className="flex-1 relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
              <Input
                placeholder="Search your library for a component..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
              />
            </div>
          </div>
        </div>

        {/* Components List */}
        <ScrollArea className="flex-1 max-h-[50vh]">
          <div className="p-6 space-y-3">
            {isLoading ? (
              <div className="text-center py-8 text-muted-foreground">
                Loading components...
              </div>
            ) : components.length === 0 ? (
              <div className="text-center py-8 text-muted-foreground">
                No components found. Try adjusting your filters.
              </div>
            ) : (
              components.map((component) => {
                const isSelected = isComponentSelected(String(component.id));
                const isExpanded = expandedComponentId === String(component.id);
                
                return (
                  <div
                    key={component.id}
                    className={cn(
                      "p-4 rounded-lg border-2 transition-all cursor-pointer",
                      isSelected 
                        ? "border-primary bg-primary/5" 
                        : "border-border hover:border-primary/50 hover:bg-muted/50",
                      isExpanded && "ring-2 ring-primary/20"
                    )}
                    onClick={() => setExpandedComponentId(
                      isExpanded ? null : String(component.id)
                    )}
                  >
                    <div className="flex items-start justify-between gap-4">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-2">
                          <Badge 
                            variant="outline" 
                            className={cn("text-xs", getTypeColor(component.type))}
                          >
                            {componentTypeConfig[component.type as ComponentType]?.label || component.type}
                          </Badge>
                          <h4 className="font-medium">{component.title}</h4>
                          {isSelected && (
                            <Badge variant="secondary" className="text-xs bg-green-100 text-green-700">
                              <Check className="w-3 h-3 mr-1" />
                              Added
                            </Badge>
                          )}
                        </div>
                        
                        <p className={cn(
                          "text-sm text-muted-foreground whitespace-pre-wrap",
                          !isExpanded && "line-clamp-2"
                        )}>
                          {component.body}
                        </p>
                        
                        {component.tags && component.tags.length > 0 && (
                          <div className="flex flex-wrap gap-1 mt-2">
                            {component.tags.map((tag) => (
                              <span 
                                key={tag.id}
                                className="text-xs px-2 py-0.5 bg-muted rounded-full text-muted-foreground"
                              >
                                {tag.name}
                              </span>
                            ))}
                          </div>
                        )}
                      </div>
                      
                      <Button
                        size="sm"
                        variant={isSelected ? "secondary" : "default"}
                        onClick={(e) => {
                          e.stopPropagation();
                          if (!isSelected) {
                            handleSelectComponent(component);
                          }
                        }}
                        disabled={isSelected}
                        className="shrink-0"
                      >
                        {isSelected ? (
                          <>
                            <Check className="w-4 h-4 mr-1" />
                            Added
                          </>
                        ) : (
                          <>
                            <Plus className="w-4 h-4 mr-1" />
                            Add
                          </>
                        )}
                      </Button>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </ScrollArea>

        {/* Footer */}
        <div className="px-6 py-4 border-t bg-muted/30 flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            {selectedComponentIds.length} component{selectedComponentIds.length !== 1 ? 's' : ''} selected
          </p>
          <Button onClick={() => onOpenChange(false)}>
            Done
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
};

export default ComponentSelector;
