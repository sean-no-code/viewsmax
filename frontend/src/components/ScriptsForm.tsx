import { useState, useEffect, useRef } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { Slider } from "@/components/ui/slider";
import { Tooltip, TooltipContent, TooltipTrigger, TooltipProvider } from "@/components/ui/tooltip";
import { Wand2, Loader2, Trash2, Edit, Save, ChevronUp, ChevronDown, Copy, Download } from "lucide-react";
import { toast } from "sonner";
import { viewsMaxApi, Script } from "@/lib/api-service";
import { useNavigate } from "react-router-dom";
import InspirationsInput, { Inspiration } from "@/components/InspirationsInput";

interface ScriptsFormProps {
  projectId?: string;
  scriptId?: string;
  projectDescription?: string;
  autoSubmit?: boolean;
  onSuccess?: (script: Script) => void;
}

const ScriptsForm = ({ projectId, scriptId, projectDescription, autoSubmit = false, onSuccess }: ScriptsFormProps) => {
  const [scriptTitle, setScriptTitle] = useState("");
  const [newScriptDescription, setNewScriptDescription] = useState("");
  const [scriptText, setScriptText] = useState("");
  const [inspirations, setInspirations] = useState<Inspiration[]>([]);
  const [scriptLength, setScriptLength] = useState([10]); // Default to 10 minutes
  const [isGenerating, setIsGenerating] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [existingScript, setExistingScript] = useState<Script | null>(null);
  const [isNewScript, setIsNewScript] = useState(false);
  const [isPolling, setIsPolling] = useState(false);
  const [isFormVisible, setIsFormVisible] = useState(true);
  const hasAutoSubmitted = useRef(false);
  const pollingIntervalRef = useRef<NodeJS.Timeout | null>(null);
  const navigate = useNavigate();

  useEffect(() => {
    if (projectDescription) {
      const truncatedDescription = projectDescription.length > 500 
        ? projectDescription.substring(0, 500) 
        : projectDescription;
      setNewScriptDescription(truncatedDescription);
    }
  }, [projectDescription]);

  useEffect(() => {
    if (projectId) {
      loadProject();
    } else if (scriptId) {
      loadScript();
    }
  }, [projectId, scriptId]);

  useEffect(() => {
    if (autoSubmit && newScriptDescription.trim() && !hasAutoSubmitted.current && !scriptId) {
      // Only auto-submit once when the component mounts with autoSubmit=true
      // and we're not editing an existing script (scriptId should be null/undefined)
      hasAutoSubmitted.current = true;
      generateScript();
    }
  }, [autoSubmit, newScriptDescription, scriptId]);

  // Cleanup polling interval on unmount
  useEffect(() => {
    return () => {
      if (pollingIntervalRef.current) {
        clearInterval(pollingIntervalRef.current);
      }
    };
  }, []);

  const loadProject = async () => {
    if (!projectId) return;
    
    try {
      setIsLoading(true);
      const result = await viewsMaxApi.getProject(projectId);
      
      if (result.success && result.data) {
        const truncatedDescription = result.data.description.length > 500 
          ? result.data.description.substring(0, 500) 
          : result.data.description;
        setNewScriptDescription(truncatedDescription);
        // Check if project has an existing script
        if (result.data.script && result.data.script.id) {
          const script = result.data.script;
          setExistingScript({
            id: script.id,
            content: script.hook + '\n\n' + script.talking_points.join('\n\n') + '\n\n' + script.close,
            title: script.title || `Script for ${result.data.description}`,
            description: result.data.description,
            status: script.status || 'completed',
            created_at: script.created_at,
            updated_at: script.updated_at
          });
          // Set the script title if it exists
          setScriptTitle(script.title || "");
        }
      } else {
        toast.error(result.error || "Failed to load project");
      }
    } catch (error) {
      console.error("Error loading project:", error);
      toast.error("Failed to load project");
    } finally {
      setIsLoading(false);
    }
  };

  const loadScript = async () => {
    if (!scriptId) return;
    
    try {
      setIsLoading(true);
      const result = await viewsMaxApi.getScriptStatus(scriptId);
      
      if (result.success && result.data) {
        setExistingScript(result.data);
        // Use project description for the input field
        setNewScriptDescription(result.data.project?.description || "");
        // Set the script title
        setScriptTitle(result.data.title || "");
        // Set the script text in the editor
        setScriptText(result.data.text || "");
        setIsNewScript(false);
      } else {
        toast.error(result.error || "Failed to load script");
      }
    } catch (error) {
      console.error("Error loading script:", error);
      toast.error("Failed to load script");
    } finally {
      setIsLoading(false);
    }
  };

  const pollScriptStatus = async (scriptId: string, skipRedirect: boolean = false) => {
    try {
      const result = await viewsMaxApi.getScriptStatus(scriptId);
      
      if (result.success && result.data) {
        const script = result.data;
        
        if (script.status === 'completed' || script.status === 'failed') {
          // Stop polling
          if (pollingIntervalRef.current) {
            clearInterval(pollingIntervalRef.current);
            pollingIntervalRef.current = null;
          }
          setIsPolling(false);
          setIsGenerating(false);
          
          if (script.status === 'completed') {
            setExistingScript(script);
            setScriptText(script.text || "");
            toast.success(skipRedirect ? "Script updated successfully" : "Script generated successfully");
            if (onSuccess) {
              onSuccess(script);
            }
            // Only redirect if not skipping (i.e., for generation, not update)
            if (!skipRedirect) {
              navigate(`/dashboard/scripts/edit/${script.id}`);
            }
          } else {
            toast.error(skipRedirect ? "Script update failed" : "Script generation failed");
          }
        }
        // If still processing, continue polling
      }
    } catch (error) {
      console.error("Error polling script status:", error);
      // Stop polling on error
      if (pollingIntervalRef.current) {
        clearInterval(pollingIntervalRef.current);
        pollingIntervalRef.current = null;
      }
      setIsPolling(false);
      setIsGenerating(false);
      toast.error("Failed to check script status");
    }
  };

  const generateScript = async () => {
    if (!scriptTitle.trim()) {
      toast.error("Please enter a title for the script");
      return;
    }

    if (!newScriptDescription.trim()) {
      toast.error("Please enter a prompt for the script");
      return;
    }

    try {
      setIsGenerating(true);
      
      // Prepare inspirations data (YouTube URLs only - files are no longer supported)
      const youtubeUrls = inspirations
        .filter(insp => insp.type === 'youtube' && insp.url)
        .map(insp => insp.url!);
      
      // Create new script - only include inspirations if we have YouTube URLs
      const scriptData: {
        prompt: string;
        title: string;
        script_length?: number;
        inspirations?: {
          youtubeUrls?: string[];
        };
      } = {
        title: scriptTitle.trim(),
        prompt: newScriptDescription,
        script_length: scriptLength[0],
      };
      
      // Only add inspirations if we have YouTube URLs
      if (youtubeUrls.length > 0) {
        scriptData.inspirations = {
          youtubeUrls: youtubeUrls,
        };
      }
      
      const result = await viewsMaxApi.createScript(scriptData);

      if (result.success && result.data) {
        const script = result.data.script || result.data;
        
        if (script) {
          // Check if the script status is processing
          if (script.status === 'processing') {
            toast.success("Script generation started! Generating script...");
            setExistingScript(script);
            setIsNewScript(false);
            setIsPolling(true);
            
            // Start polling every 3 seconds
            pollingIntervalRef.current = setInterval(() => {
              pollScriptStatus(script.id.toString());
            }, 3000);
          } else if (script.status === 'completed') {
            toast.success("Script generated successfully");
            setExistingScript(script);
            setScriptText(script.text || "");
            setIsNewScript(false);
            if (onSuccess) {
              onSuccess(script);
            }
            // Redirect to script edit page
            navigate(`/dashboard/scripts/edit/${script.id}`);
          } else {
            toast.error("Script generation failed");
          }
        } else {
          toast.error("No script data received");
        }
      } else {
        toast.error(result.error || "Failed to generate script");
      }
    } catch (error) {
      console.error("Error generating script:", error);
      const errorMessage = error instanceof Error ? error.message : "Failed to generate script";
      toast.error(errorMessage);
    } finally {
      setIsGenerating(false);
    }
  };

  const saveScript = async () => {
    if (!existingScript || !scriptText.trim()) {
      toast.error("No script to save");
      return;
    }

    try {
      setIsSaving(true);
      const updateData: {
        text: string;
        title?: string;
      } = {
        text: scriptText,
      };
      
      // Include title if it's been modified
      if (scriptTitle.trim()) {
        updateData.title = scriptTitle.trim();
      }
      
      const result = await viewsMaxApi.updateScript(existingScript.id.toString(), updateData);

      if (result.success && result.data) {
        toast.success("Script saved successfully");
        // Update the existing script with the new data
        setExistingScript(result.data);
      } else {
        toast.error(result.error || "Failed to save script");
      }
    } catch (error) {
      console.error("Error saving script:", error);
      toast.error("Failed to save script");
    } finally {
      setIsSaving(false);
    }
  };

  const updateScriptWithPrompt = async () => {
    if (!existingScript || !scriptText.trim() || !newScriptDescription.trim()) {
      toast.error("Script text and prompt are required");
      return;
    }

    try {
      setIsSaving(true);
      const updateData: {
        text: string;
        prompt?: string;
        title?: string;
      } = {
        text: scriptText,
        prompt: newScriptDescription,
      };
      
      // Include title if it's been modified
      if (scriptTitle.trim()) {
        updateData.title = scriptTitle.trim();
      }
      
      const result = await viewsMaxApi.updateScript(existingScript.id.toString(), updateData);

      if (result.success && result.data) {
        const script = result.data;
        
        // Check if the script status is processing
        if (script.status === 'processing') {
          toast.success("Script update started! Updating script...");
          setExistingScript(script);
          setIsPolling(true);
          
          // Start polling every 3 seconds
          pollingIntervalRef.current = setInterval(() => {
            pollScriptStatus(script.id.toString(), true);
          }, 3000);
        } else if (script.status === 'completed') {
          toast.success("Script updated successfully");
          // Update the existing script with the new data
          setExistingScript(script);
          setScriptText(script.text || "");
        } else {
          toast.error("Script update failed");
        }
      } else {
        toast.error(result.error || "Failed to update script");
      }
    } catch (error) {
      console.error("Error updating script:", error);
      toast.error("Failed to update script");
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) {
    return (
      <div className="space-y-6 max-w-6xl">
        <div className="flex items-center justify-center h-64">
          <div className="text-center">
            <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4" />
            <p className="text-muted-foreground">Loading project...</p>
          </div>
        </div>
      </div>
    );
  }

  // Show processing state for script generation or update
  if ((isGenerating || isPolling) && existingScript?.status === 'processing') {
    return (
      <div className="space-y-6 max-w-6xl">
        <div className="flex items-center justify-center h-64">
          <div className="text-center">
            <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4" />
            <p className="text-muted-foreground">
              {isPolling && !isGenerating ? "Updating script..." : "Generating script..."}
            </p>
          </div>
        </div>
      </div>
    );
  }

  const isEditMode = !!scriptId;

  // Calculate word count
  const wordCount = scriptText.trim() ? scriptText.trim().split(/\s+/).filter(word => word.length > 0).length : 0;

  // Copy script to clipboard
  const handleCopy = async () => {
    if (!scriptText.trim()) {
      toast.error("No script content to copy");
      return;
    }

    try {
      await navigator.clipboard.writeText(scriptText);
      toast.success("Script copied to clipboard");
    } catch (error) {
      console.error("Error copying to clipboard:", error);
      toast.error("Failed to copy script");
    }
  };

  // Download script as text file
  const handleDownload = () => {
    if (!scriptText.trim()) {
      toast.error("No script content to download");
      return;
    }

    try {
      const filename = scriptTitle.trim() || "script";
      const blob = new Blob([scriptText], { type: "text/plain" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = `${filename}.txt`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
      toast.success("Script downloaded successfully");
    } catch (error) {
      console.error("Error downloading script:", error);
      toast.error("Failed to download script");
    }
  };

  return (
    <div className="flex flex-col gap-6 max-w-6xl min-h-[calc(100vh-8rem)]">
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="text-lg">{isEditMode ? "Edit Script" : "Generate Script"}</CardTitle>
              <CardDescription>
                {isEditMode ? "Edit your script details and regenerate if needed" : "Create engaging video scripts based on your prompt"}
              </CardDescription>
            </div>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setIsFormVisible(!isFormVisible)}
              className="gap-2"
            >
              {isFormVisible ? (
                <>
                  <ChevronUp className="w-4 h-4" />
                  Hide Form
                </>
              ) : (
                <>
                  <ChevronDown className="w-4 h-4" />
                  Show Form
                </>
              )}
            </Button>
          </div>
        </CardHeader>
        {isFormVisible && (
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="title">Title <span className="text-destructive">*</span></Label>
            <Input
              id="title"
              name="title"
              placeholder="Enter script title..."
              value={scriptTitle}
              onChange={(e) => setScriptTitle(e.target.value)}
              className={!scriptTitle.trim() ? 'border-red-500' : ''}
              required
            />
          </div>

          <div className="space-y-2">
            <div className="flex justify-between items-center">
              <Label htmlFor="project">Prompt <span className="text-destructive">*</span></Label>
              <span className={`text-sm ${newScriptDescription.length === 500 ? 'text-destructive font-medium' : 'text-muted-foreground'}`}>
                {newScriptDescription.length}/500
              </span>
            </div>
            <Textarea
              id="project"
              name="project"
              placeholder="Enter your prompt to generate a compelling script..."
              value={newScriptDescription}
              onChange={(e) => {
                if (e.target.value.length <= 500) {
                  setNewScriptDescription(e.target.value);
                }
              }}
              className={`min-h-[120px] ${newScriptDescription.length === 500 ? 'border-red-500' : ''}`}
              maxLength={500}
              required
            />
          </div>

          {/* Script Length Slider - Only show when not in edit mode */}
          {!isEditMode && (
            <div className="space-y-2">
              <div className="flex justify-between items-center">
                <div className="flex items-center gap-2">
                  <Label htmlFor="script-length">Script Length (minutes)</Label>
                  <TooltipProvider>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Button variant="ghost" size="sm" className="h-4 w-4 p-0">
                          <span className="sr-only">Help</span>
                          <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                          </svg>
                        </Button>
                      </TooltipTrigger>
                      <TooltipContent>
                        <p>Upgrade for longer scripts</p>
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                </div>
                <span className="text-sm text-muted-foreground">
                  {scriptLength[0]} {scriptLength[0] === 1 ? 'minute' : 'minutes'}
                </span>
              </div>
              <Slider
                id="script-length"
                min={1}
                  max={60}
                step={1}
                value={scriptLength}
                onValueChange={(value) => {
                    // Ensure value doesn't exceed 60
                    setScriptLength([Math.min(value[0], 60)]);
                }}
                className="w-full"
              />
            </div>
          )}

          {/* Inspirations Input - Only show when not in edit mode */}
          {!isEditMode && (
            <InspirationsInput
              inspirations={inspirations}
              onChange={setInspirations}
            />
          )}

          <div className="flex justify-end gap-2">
            <Button 
              variant="outline"
              onClick={() => navigate('/dashboard/scripts')}
            >
              Cancel
            </Button>
            {existingScript && (
              <Button 
                onClick={updateScriptWithPrompt} 
                disabled={isSaving || isPolling || !scriptText.trim() || !newScriptDescription.trim()}
                variant="secondary"
                className="gap-2 bg-gray-200 hover:bg-gray-300 text-gray-700 hover:text-gray-800"
              >
                {(isSaving || isPolling) ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <Save className="w-4 h-4" />
                )}
                {(isSaving || isPolling) ? "Updating..." : "Update"}
              </Button>
            )}
            {!existingScript && (
              <Button 
                onClick={generateScript} 
                disabled={isGenerating || !scriptTitle.trim() || !newScriptDescription.trim()}
                className="gap-2"
              >
                {isGenerating ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <Wand2 className="w-4 h-4" />
                )}
                {isGenerating ? "Generating..." : "Generate script with A.I"}
              </Button>
            )}
          </div>
        </CardContent>
        )}
      </Card>

      {/* Generated Script Section */}
      {existingScript && (
        <Card className={`flex flex-col transition-all duration-500 flex-1 ${
          isNewScript 
            ? 'border-blue-300 bg-blue-50/50 shadow-md animate-in fade-in-0 slide-in-from-left-2' 
            : ''
        }`}>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="text-lg flex items-center gap-2">
                  Generated Script
                  {isNewScript && (
                    <Badge variant="secondary" className="text-xs bg-blue-100 text-blue-700">
                      NEW
                    </Badge>
                  )}
                </CardTitle>
              </div>
            </div>
          </CardHeader>
          <CardContent className="flex-1 flex flex-col">
            <div className="space-y-4 flex-1 flex flex-col">
              {existingScript.description && (
                <div>
                  <Label className="text-sm font-medium">Description</Label>
                  <p className="text-sm text-muted-foreground mt-1">
                    {existingScript.description}
                  </p>
                </div>
              )}
              
              <div className="flex-1 flex flex-col">
                <div className="flex items-center justify-between mb-2">
                  <Label htmlFor="script-text" className="text-sm font-medium">Script Content</Label>
                  <div className="flex items-center gap-3">
                    <span className="text-sm text-muted-foreground">
                      {wordCount.toLocaleString()} {wordCount === 1 ? 'word' : 'words'}
                    </span>
                    <div className="flex items-center gap-1">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={handleCopy}
                        disabled={!scriptText.trim()}
                        className="h-8 w-8 p-0"
                        title="Copy to clipboard"
                      >
                        <Copy className="w-4 h-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={handleDownload}
                        disabled={!scriptText.trim()}
                        className="h-8 w-8 p-0"
                        title="Download as text file"
                      >
                        <Download className="w-4 h-4" />
                      </Button>
                      {existingScript && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={saveScript}
                          disabled={isSaving || !scriptText.trim()}
                          className="h-8 px-2 gap-1"
                          title="Save script"
                        >
                          {isSaving ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                          ) : (
                            <Save className="w-4 h-4" />
                          )}
                          <span className="text-xs">Save</span>
                        </Button>
                      )}
                    </div>
                  </div>
                </div>
                <Textarea
                  id="script-text"
                  value={scriptText}
                  onChange={(e) => setScriptText(e.target.value)}
                  className="mt-1 flex-1 font-mono text-sm"
                  placeholder="Script content will appear here..."
                />
              </div>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default ScriptsForm;





