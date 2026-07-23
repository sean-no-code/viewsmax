import { useState, useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { Loader2, MessageSquare, Search, FileText, Sparkles, Check, ChevronRight, Copy, Download, Save, RefreshCw, Plus, X, ExternalLink, ChevronLeft, History, Eye, ArrowLeft } from "lucide-react";
import { toast } from "sonner";
import { viewsMaxApi, Script, ScriptResearch, LibraryComponent, ScriptVersion } from "@/lib/api-service";
import { componentTypeConfig, ComponentType } from "@/lib/scripts-static-data";
import { cn } from "@/lib/utils";
import ComponentSelector from "./ComponentSelector";
import InspirationsInput, { Inspiration } from "@/components/InspirationsInput";
import { Slider } from "@/components/ui/slider";
import { Label } from "@/components/ui/label";
import { useAuth } from "@/hooks/useAuth";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";

interface ScriptWizardProps {
  initialTopic?: string;
  scriptId?: string;
  onScriptGenerated?: (script: Script) => void;
}

type WizardStep = 'topic' | 'research' | 'components' | 'script';

const ScriptWizard = ({ initialTopic = '', scriptId, onScriptGenerated }: ScriptWizardProps) => {
  // Wizard state
  const [currentStep, setCurrentStep] = useState<WizardStep>('topic');
  const [completedSteps, setCompletedSteps] = useState<WizardStep[]>([]);
  const { refreshUser } = useAuth();
  
  // Topic state
  const [topic, setTopic] = useState(initialTopic);
  const [title, setTitle] = useState('');
  const [scriptLength, setScriptLength] = useState<number[]>([10]);
  const [inspirations, setInspirations] = useState<Inspiration[]>([]);
  const [isResearchLoading, setIsResearchLoading] = useState(false);
  
  // Research state
  const [research, setResearch] = useState<ScriptResearch | null>(null);
  const [researchRaw, setResearchRaw] = useState<string>("");
  
  // Components state
  const [selectedComponents, setSelectedComponents] = useState<LibraryComponent[]>([]);
  const [isComponentSelectorOpen, setIsComponentSelectorOpen] = useState(false);
  
  // Script state
  const [generatedScript, setGeneratedScript] = useState<Script | null>(null);
  const [scriptContent, setScriptContent] = useState('');
  const [isGeneratingScript, setIsGeneratingScript] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [refinePrompt, setRefinePrompt] = useState('');
  const [isRefining, setIsRefining] = useState(false);

  // Auto-saved & History
  const [lastSavedContent, setLastSavedContent] = useState('');
  const [isAutoSaving, setIsAutoSaving] = useState(false);
  const [scriptVersions, setScriptVersions] = useState<ScriptVersion[]>([]);
  const [isHistoryOpen, setIsHistoryOpen] = useState(false);
  const [viewingVersion, setViewingVersion] = useState<ScriptVersion | null>(null);
  const [versionToRestore, setVersionToRestore] = useState<ScriptVersion | null>(null);

  // Load Script Data on Mount (if scriptId provided)
  useEffect(() => {
    if (scriptId) {
      const loadScript = async () => {
        const result = await viewsMaxApi.getScript(scriptId);
        if (result.success && result.data) {
          const s = result.data;

          // Set Topic
          setTopic(s.prompt || '');
          setTitle(s.title || '');
          if (s.length) setScriptLength([s.length]);

          // Set Research
          if (s.research) {
            setResearch(s.research);
            setResearchRaw(s.research.body || '');
          }

          // Set Components
          if (s.components) {
            setSelectedComponents(s.components);
          }

          // Set Script
          if (s.text) {
            setGeneratedScript(s);
            setGeneratedScript(s);
            setScriptContent(s.text);
            setLastSavedContent(s.text);
          }

          // Set Inspiration Videos
          if (s.videos && s.videos.length > 0) {
            const inspirations: Inspiration[] = s.videos.map(v => ({
              id: v.id?.toString() || Math.random().toString(),
              type: 'youtube',
              url: `https://www.youtube.com/watch?v=${v.youtube_video_id}`,
              title: v.title || 'Inspiration Video',
              thumbnailUrl: `https://img.youtube.com/vi/${v.youtube_video_id}/mqdefault.jpg`,
            }));
            setInspirations(inspirations);
          }

          // Set Selected Components
          if (s.components && s.components.length > 0) {
            setSelectedComponents(s.components);
          }

          // Set Stage
          // Map backend stage (1,2,3,4) to WizardStep
          const stage = s.stage || 1;
          if (stage >= 4) {
            setCurrentStep('script');
            setCompletedSteps(['topic', 'research', 'components']);
          } else if (stage === 3) {
            setCurrentStep('components');
            setCompletedSteps(['topic', 'research']);
          } else if (stage === 2) {
            setCurrentStep('research');
            setCompletedSteps(['topic']);
          } else {
            setCurrentStep('topic');
            setCompletedSteps([]);
          }
        } else {
          toast.error("Failed to load script");
        }
      };
      loadScript();
    }
  }, [scriptId]);

  // Step configuration
  const steps: { id: WizardStep; label: string; icon: React.ReactNode }[] = [
    { id: 'topic', label: 'Topic', icon: <MessageSquare className="w-4 h-4" /> },
    { id: 'research', label: 'Research', icon: <Search className="w-4 h-4" /> },
    { id: 'components', label: 'Components', icon: <Plus className="w-4 h-4" /> },
    { id: 'script', label: 'Script', icon: <FileText className="w-4 h-4" /> },
  ];

  const jumpToStep = (step: WizardStep) => {
    // Allow jumping to any enabled step
    if (isStepEnabled(step)) {
      setCurrentStep(step);
    }
  };

  const goToNextStep = () => {
    const currentIndex = steps.findIndex(s => s.id === currentStep);
    if (currentIndex < steps.length - 1) {
      setCurrentStep(steps[currentIndex + 1].id);
    }
  };

  const goToPreviousStep = () => {
    const currentIndex = steps.findIndex(s => s.id === currentStep);
    if (currentIndex > 0) {
      setCurrentStep(steps[currentIndex - 1].id);
    }
  };

  // Handle topic submission and fetch research
  const handleTopicSubmit = async () => {
    if (!topic.trim()) {
      toast.error("Please enter a topic for your script");
      return;
    }

    if (topic.length > 500) {
      toast.error("Topic must be 500 characters or less");
      return;
    }

    setIsResearchLoading(true);
    
    try {
      let result;
      if (scriptId) {
        await viewsMaxApi.updateScript(Number(scriptId), {
          title: title || topic.slice(0, 50),
          prompt: topic,
        } as any);

        // Then trigger research generation
        result = await viewsMaxApi.createScriptResearch(Number(scriptId));
      } else {
        // Create new script
        // Extract YouTube URLs
        const youtubeUrls = inspirations
          .filter(i => i.type === 'youtube' && i.url)
          .map(i => i.url)
          .filter(Boolean) as string[];

        result = await viewsMaxApi.createScript({
          title: title || topic.slice(0, 50),
          prompt: topic,
          script_length: scriptLength[0],
          inspirations: youtubeUrls.length > 0 ? { youtubeUrls } : undefined
        });
      }
      
      if (result.success && result.data) {
        const s = result.data;
        const id = s.id;
        setGeneratedScript(s);
        setTopic(s.prompt || topic);
        setTitle(s.title || title);
        setCurrentStep('research');
        setCompletedSteps((prev) => [...prev, 'topic']);

        // POLL for completion
        const pollInterval = setInterval(async () => {
          const check = await viewsMaxApi.getScript(String(id)); // getScript expects string based on effect hook usage
          if (check.success && check.data) {
            const updatedScript = check.data;
            const researchStatus = updatedScript.research?.status;

            if (researchStatus === 'completed') {
              clearInterval(pollInterval);
              setResearch(updatedScript.research);
              setResearchRaw(updatedScript.research.body || '');
              setIsResearchLoading(false);
              toast.success("Research completed!");
            } else if (researchStatus === 'failed') {
              clearInterval(pollInterval);
              setIsResearchLoading(false);
              setResearch(updatedScript.research); // Set it so we can show error state/retry button
              toast.error("Research failed. Please retry.");
            }
            // If processing, continue polling
          } else {
            // API error, maybe transient
          }
        }, 2000); // Poll every 2 seconds

      } else {
        toast.error(result.error || "Failed to save topic");
        setIsResearchLoading(false);
      }
    } catch (error) {
      console.error("Error fetching research:", error);
      toast.error("An error occurred");
      setIsResearchLoading(false);
    }
  };

  // Manual Retry Handler
  const handleRetryResearch = async () => {
    const id = scriptId || generatedScript?.id;
    if (!id) return;

    setIsResearchLoading(true);
    try {
      const result = await viewsMaxApi.createScriptResearch(Number(id));
      if (result.success) {
        // Start polling again
        const pollInterval = setInterval(async () => {
          const check = await viewsMaxApi.getScript(String(id));
          if (check.success && check.data) {
            const updatedScript = check.data;
            const researchStatus = updatedScript.research?.status;

            if (researchStatus === 'completed') {
              clearInterval(pollInterval);
              setResearch(updatedScript.research);
              setResearchRaw(updatedScript.research.body || '');
              setIsResearchLoading(false);
              toast.success("Research completed!");
            } else if (researchStatus === 'failed') {
              clearInterval(pollInterval);
              setIsResearchLoading(false);
              setResearch(updatedScript.research);
              toast.error("Research failed. Please retry.");
            }
          }
        }, 2000);
      } else {
        toast.error("Failed to start retry: " + result.error);
        setIsResearchLoading(false);
      }
    } catch (e) {
      console.error(e);
      toast.error("Retry failed");
      setIsResearchLoading(false);
    }
  };

  const handleTitleBlur = async () => {
    const id = scriptId || generatedScript?.id;
    if (id && title) {
      // update script title
      try {
        await viewsMaxApi.updateScript(Number(id), { title });
      } catch (e) { console.error("Failed to auto-save title", e); }
    }
  };

  const handleTopicBlur = async () => {
    const id = scriptId || generatedScript?.id;
    if (id && topic) {
      // update script prompt
      try {
        await viewsMaxApi.updateScript(Number(id), { prompt: topic });
      } catch (e) { console.error("Failed to auto-save topic", e); }
    }
  };


  const applyResearchRawToResearch = async (raw: string) => {
    if (!research) return;
    setResearch({
      ...research,
      body: raw
    });

    // Auto-save research on blur
    const id = scriptId || generatedScript?.id;
    if (id) {
      try {
        await viewsMaxApi.updateScriptResearch(Number(id), raw);
      } catch (e) { console.error("Failed to auto-save research", e); }
    }
  };

  const handleResearchContinue = async () => {
    if (!research) return;

    // Save Research modifications
    if (scriptId || generatedScript?.id) { // generatedScript here holds the script object if loaded
      const id = scriptId || generatedScript?.id;
      if (id) {
        await viewsMaxApi.updateScriptResearch(Number(id), researchRaw);
      }
    }

    setCurrentStep('components');
    setCompletedSteps((prev) => [...prev, 'research']);
  };

  const saveComponents = async (components: LibraryComponent[]) => {
    const id = scriptId || generatedScript?.id;
    if (id) {
      try {
        await viewsMaxApi.saveScriptComponents(Number(id), components.map(c => c.id));
      } catch (e) {
        console.error("Auto-save components failed", e);
      }
    }
  };

  // Handle adding a component
  const handleAddComponent = (component: LibraryComponent) => {
    if (!selectedComponents.find(c => c.id === component.id)) {
      const updated = [...selectedComponents, component];
      setSelectedComponents(updated);
      toast.success(`Added "${component.title}"`);
      saveComponents(updated);
    }
  };

  // Handle removing a component
  const handleRemoveComponent = (componentId: number) => {
    const updated = selectedComponents.filter(c => c.id !== componentId);
    setSelectedComponents(updated);
    saveComponents(updated);
  };

  // Handle continuing from components to script generation
  const handleComponentsContinue = async () => {
    // Save Components
    const id = scriptId || generatedScript?.id;
    if (id) {
      try {
        await viewsMaxApi.saveScriptComponents(Number(id), selectedComponents.map(c => c.id));
      } catch (e) { console.error("Failed to save components", e); }
    }

    setCompletedSteps(prev => [...prev.filter(s => s !== 'components'), 'components']);
    goToNextStep();
    
    // Generate script
    if (id && generatedScript?.status !== 'completed') {
    await generateScript();
    }
  };

  // Handle manual script edits
  const handleManualSave = async () => {
    const id = scriptId || generatedScript?.id;
    if (!id) return;

    setIsSaving(true);
    try {
      const res = await viewsMaxApi.saveScript(Number(id), scriptContent);
      if (res.success) {
        toast.success(res.message || "Script saved successfully");
        setGeneratedScript(prev => prev ? { ...prev, text: scriptContent } : null);
        setLastSavedContent(scriptContent);
      } else {
        toast.error("Failed to save: " + res.error);
      }
    } catch (e) {
      console.error(e);
      toast.error("Failed to save changes");
    } finally {
      setIsSaving(false);
    }
  };

  // Auto-Save Effect
  useEffect(() => {
    const id = scriptId || generatedScript?.id;
    if (!id || !scriptContent || scriptContent === lastSavedContent) return;

    const timeoutId = setTimeout(async () => {
      setIsAutoSaving(true);
      try {
        await viewsMaxApi.saveScript(id, scriptContent);
        setLastSavedContent(scriptContent);

        if (isHistoryOpen) {
          loadHistory(id);
        }
      } catch (e) {
        console.error("Auto-save failed", e);
      } finally {
        setIsAutoSaving(false);
      }
    }, 1500); // 1.5 seconds debounce

    return () => clearTimeout(timeoutId);
  }, [scriptContent, scriptId, generatedScript?.id, lastSavedContent, isHistoryOpen]);

  const loadHistory = async (id: number | string) => {
    const res = await viewsMaxApi.getScriptHistory(id);
    if (res.success && res.data) {
      setScriptVersions(res.data);
    }
  };

  const handleOpenHistory = async () => {
    const id = scriptId || generatedScript?.id;
    if (!id) return;

    setIsHistoryOpen(true);
    await loadHistory(id);
  };

  const handleRestoreVersion = (version: ScriptVersion) => {
    setVersionToRestore(version);
  };

  const confirmRestore = async () => {
    if (!versionToRestore) return;

    // Auto-save current changes if strictly different
    if (scriptContent !== lastSavedContent) {
      // toast.info("Saving current changes before restoring...");
      const id = scriptId || generatedScript?.id;
      if (id) {
        try {
          await viewsMaxApi.saveScript(id, scriptContent);
        } catch (e) {
          console.error("Failed to auto-save before restore", e);
          toast.error("Failed to save current changes. Restore cancelled.");
          return;
        }
      }
    }

    setScriptContent(versionToRestore.content);
    setLastSavedContent(versionToRestore.content);
    setViewingVersion(null);
    setVersionToRestore(null);
    setIsHistoryOpen(false);
    toast.success("Version restored successfully");
  };

  // Refine Script
  const handleRefine = async () => {
    const id = scriptId || generatedScript?.id;
    if (!id || !refinePrompt.trim()) return;

    setIsRefining(true);

    try {
      // Call updateScript with refine: true
      const result = await viewsMaxApi.updateScript(Number(id), {
        prompt: refinePrompt,
        refine: true
      });

      if (result.success && result.data) {
        // setRefinePrompt(''); // Keep prompt for retry
        toast.info("Updating script...");

        // Poll for completion
        const pollInterval = setInterval(async () => {
          const check = await viewsMaxApi.getScript(Number(id));
          if (check.success && check.data) {
            if (check.data.status === 'completed') {
              clearInterval(pollInterval);
              setGeneratedScript(check.data);
              setScriptContent(check.data.text || '');
              setLastSavedContent(check.data.text || '');
              setRefinePrompt('');
              setIsRefining(false);
              toast.success("Script refined successfully!");
              refreshUser();
            } else if (check.data.status === 'failed') {
              clearInterval(pollInterval);
              setIsRefining(false);
              toast.error("Refinement failed: " + check.data.error_message);
            }
          }
        }, 3000);
      } else {
        toast.error(result.error || "Failed to start refinement");
        setIsRefining(false);
      }
    } catch (e) {
      console.error(e);
      toast.error("Refinement error");
      setIsRefining(false);
    }
  };

  // Generate script
  const generateScript = async () => {
    const id = scriptId || generatedScript?.id;
    if (!id) return;
    
    setIsGeneratingScript(true);
    
    try {
      const result = await viewsMaxApi.generateFinalScript(Number(id));
      
      if (result.success && result.data) {
        // Poll for completion
        const pollInterval = setInterval(async () => {
          const check = await viewsMaxApi.getScript(Number(id));
          if (check.success && check.data) {
            if (check.data.status === 'completed') {
              clearInterval(pollInterval);
              setGeneratedScript(check.data);
              setScriptContent(check.data.text || '');
              setLastSavedContent(check.data.text || '');
        setCompletedSteps(prev => [...prev.filter(s => s !== 'script'), 'script']);
              setIsGeneratingScript(false);
        if (onScriptGenerated) {
                onScriptGenerated(check.data);
        }

              // Refresh user credits
              refreshUser();
        
        toast.success("Script generated successfully!");
            } else if (check.data.status === 'failed') {
              clearInterval(pollInterval);
              setIsGeneratingScript(false);
              toast.error("Generation failed: " + check.data.error_message);
            }
            // If processing, continue polling
      } else {
            // If fetch failed, stop polling? Or retry?
            // Let's retry a few times in real app, but here maybe just log
          }
        }, 3000);
      } else {
        toast.error(result.error || "Failed to start generation");
        setIsGeneratingScript(false);
      }
    } catch (error) {
      console.error("Error generating script:", error);
      toast.error("Failed to generate script");
      setIsGeneratingScript(false);
    }
  };

  // Copy script to clipboard
  const handleCopy = async () => {
    if (!scriptContent.trim()) {
      toast.error("No script content to copy");
      return;
    }

    try {
      await navigator.clipboard.writeText(scriptContent);
      toast.success("Script copied to clipboard");
    } catch (error) {
      console.error("Error copying to clipboard:", error);
      toast.error("Failed to copy script");
    }
  };

  // Download script as text file
  const handleDownload = () => {
    if (!scriptContent.trim()) {
      toast.error("No script content to download");
      return;
    }

    try {
      const filename = topic.trim() || "script";
      const blob = new Blob([scriptContent], { type: "text/plain" });
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

  // Calculate word count
  const wordCount = scriptContent.trim() 
    ? scriptContent.trim().split(/\s+/).filter(word => word.length > 0).length 
    : 0;

  const isStepCompleted = (step: WizardStep) => completedSteps.includes(step);
  const isStepActive = (step: WizardStep) => currentStep === step;
  const isStepEnabled = (step: WizardStep) => {
    const stepIndex = steps.findIndex(s => s.id === step);
    const currentIndex = steps.findIndex(s => s.id === currentStep);

    // First step is always enabled
    if (stepIndex === 0) return true;

    // Current step is always enabled
    if (isStepActive(step)) return true;

    // Completed steps are always enabled (can navigate back)
    if (isStepCompleted(step)) return true;

    // Enable next step after current (forward navigation)
    if (stepIndex === currentIndex + 1) {
    const prevStep = steps[stepIndex - 1];
    return isStepCompleted(prevStep.id);
    }

    // For other steps, check if previous step is completed
    const prevStep = steps[stepIndex - 1];
    return isStepCompleted(prevStep.id);
  };

  const currentStepIndex = steps.findIndex(s => s.id === currentStep);


  // Helper for date formatting
  const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    }).format(date);
  };

  return (
    <div className="w-full">
      {/* Horizontal Step Navigation */}
      <div className="mb-8">
        <div className="flex items-center justify-between max-w-4xl mx-auto">
          {steps.map((step, index) => {
            const isCompleted = isStepCompleted(step.id);
            const isActive = isStepActive(step.id);
            const isEnabled = isStepEnabled(step.id);
            
            return (
              <div key={step.id} className="flex items-center flex-1">
                <div className="flex flex-col items-center flex-1">
                  <button
                    onClick={() => jumpToStep(step.id)}
                    disabled={!isEnabled && !isActive}
                    className={cn(
                      "flex items-center justify-center w-10 h-10 rounded-full border-2 transition-all mb-2",
                      isActive && "border-primary bg-primary text-primary-foreground shadow-md scale-110",
                      isCompleted && !isActive && "border-green-500 bg-green-100 text-green-700",
                      !isEnabled && !isActive && "border-muted bg-muted text-muted-foreground opacity-40 cursor-not-allowed",
                      isEnabled && !isActive && !isCompleted && "border-border bg-background hover:border-primary/50"
                    )}
                  >
                    {isCompleted && !isActive ? (
                      <Check className="w-5 h-5" />
                    ) : (
                      step.icon
                    )}
                  </button>
                  <span className={cn(
                    "text-sm font-medium",
                    isActive && "text-primary",
                    isCompleted && !isActive && "text-green-700",
                    !isEnabled && !isActive && "text-muted-foreground opacity-40"
                  )}>
                    {step.label}
                  </span>
                </div>
                {index < steps.length - 1 && (
                  <div className={cn(
                    "flex-1 h-0.5 mx-2 transition-all",
                    isCompleted || (index < currentStepIndex) ? "bg-green-500" : "bg-muted"
                  )} />
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Main Content Area - Horizontal Layout */}
      <div className="relative overflow-hidden">
        <div 
          className="flex transition-transform duration-300 ease-in-out"
          style={{ transform: `translateX(-${currentStepIndex * 100}%)` }}
        >
          {/* Step 1: Topic */}
          <div className="w-full flex-shrink-0 px-1">
            <div className="max-w-4xl mx-auto">
              <Card className="border-2 border-primary/50 shadow-md">
              <CardHeader>
                <div className="flex items-center justify-between gap-4">
                  <div className="flex items-center gap-2">
                    <div className="w-8 h-8 rounded-full flex items-center justify-center bg-primary/10">
                      <MessageSquare className="w-4 h-4 text-primary" />
                    </div>
                    <h3 className="font-semibold">Describe your topic</h3>
                    <a
                      href="#"
                      className="text-sm text-primary hover:underline inline-flex items-center gap-1"
                    >
                      How to write a prompt
                      <ExternalLink className="w-3 h-3" />
                    </a>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="script-title">Title</Label>
                    <Input
                      id="script-title"
                      placeholder="Enter script title..."
                      value={title}
                      onChange={(e) => setTitle(e.target.value)}
                      onBlur={handleTitleBlur}
                    />
                  </div>

                <div className="space-y-2">
                  <div className="flex justify-between items-center">
                    <label className="text-sm font-medium">Topic</label>
                    <span className={cn(
                      "text-sm",
                      topic.length === 500 ? "text-destructive font-medium" : "text-muted-foreground"
                    )}>
                      {topic.length}/500
                    </span>
                  </div>
                  <Textarea
                    placeholder="Write a script about writing the best YouTube scripts"
                    value={topic}
                    onChange={(e) => {
                      if (e.target.value.length <= 500) {
                        setTopic(e.target.value);
                      }
                    }}
                      onBlur={handleTopicBlur}
                    className="min-h-[120px] text-base"
                    maxLength={500}
                  />
                </div>

                <div className="space-y-2">
                  <div className="flex justify-between items-center">
                    <Label htmlFor="script-length">Script Length (minutes)</Label>
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
                    onValueChange={(value) => setScriptLength([Math.min(value[0], 60)])}
                    className="w-full"
                  />
                </div>

                {/* Optional YouTube Inspiration */}
                <div className="space-y-2">
                  <label className="text-sm font-medium text-muted-foreground">Add an inspiration (Optional)</label>
                  <InspirationsInput
                    inspirations={inspirations}
                    onChange={setInspirations}
                  />
                </div>

                <div className="flex items-center justify-end gap-2 pt-4">
                  <Button 
                    onClick={handleTopicSubmit}
                    disabled={isResearchLoading || !topic.trim()}
                  >
                    {isResearchLoading ? (
                      <>
                        <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                        Researching...
                      </>
                    ) : (
                      <>
                        Next: Research
                        <ChevronRight className="w-4 h-4 ml-2" />
                      </>
                    )}
                  </Button>
                </div>
              </CardContent>
            </Card>
            </div>
          </div>

          {/* Step 2: Research */}
          <div className="w-full flex-shrink-0 px-1">
            <div className="max-w-4xl mx-auto">
              <Card className={cn(
              "border-2 transition-all",
              isStepActive('research') ? "border-primary/50 shadow-md" : "border-border",
              !isStepEnabled('research') && "opacity-40 pointer-events-none"
            )}>
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <div className={cn(
                      "w-8 h-8 rounded-full flex items-center justify-center",
                      isStepCompleted('research') ? "bg-green-100" : isStepActive('research') ? "bg-primary/10" : "bg-muted"
                    )}>
                      {isStepCompleted('research') ? (
                        <Check className="w-4 h-4 text-green-600" />
                      ) : (
                        <Search className={cn("w-4 h-4", isStepActive('research') ? "text-primary" : "text-muted-foreground")} />
                      )}
                    </div>
                    <h3 className={cn("font-semibold", !isStepActive('research') && !isStepCompleted('research') && "text-muted-foreground")}>
                      Review the research
                    </h3>
                  </div>
                  {isStepEnabled('research') && (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        disabled={!isStepActive('research') || isResearchLoading}
                        onClick={handleRetryResearch}
                        title="Rerun Research"
                      >
                      <RefreshCw className="w-4 h-4" />
                    </Button>
                  )}
                </div>
              </CardHeader>
              <CardContent className="space-y-6">
                  {/* Research Step Content */}
                  {isResearchLoading || (research && research.status === 'processing') ? (
                    <div className="flex items-center justify-center py-12">
                      <div className="text-center">
                        <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4 text-primary" />
                        <p className="text-muted-foreground">Research in Progress...</p>
                      </div>
                    </div>
                  ) : research && research.status === 'failed' ? (
                    <div className="flex flex-col items-center justify-center py-12 space-y-4 text-center">
                      <div className="w-12 h-12 rounded-full bg-destructive/10 flex items-center justify-center">
                        <X className="w-6 h-6 text-destructive" />
                      </div>
                      <div>
                        <h3 className="text-lg font-medium text-destructive">Research Failed</h3>
                        <p className="text-sm text-muted-foreground mt-1 mb-4">
                          We couldn't complete the research. Please try again.
                        </p>
                        <Button variant="outline" onClick={handleRetryResearch}>
                          <RefreshCw className="w-4 h-4 mr-2" />
                          Retry Research
                        </Button>
                      </div>
                    </div>
                  ) : research ? (
                  <>
                    {/* Sources */}
                      {research.references && research.references.length > 0 && (
                        <div className="flex flex-wrap gap-2 mb-4">
                          {research.references.map((source, idx) => (
                        <a
                            key={idx}
                          href={source.url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="flex items-center gap-2 px-3 py-1.5 bg-muted rounded-full text-sm hover:bg-muted/80 transition-colors"
                        >
                            {/* Favicon can be derived or omitted */}
                          <img 
                              src={`https://www.google.com/s2/favicons?domain=${source.url}`}
                            alt="" 
                            className="w-4 h-4 rounded"
                            onError={(e) => {
                              (e.target as HTMLImageElement).style.display = 'none';
                            }}
                          />
                            {source.title}
                        </a>
                      ))}
                    </div>
                      )}

                    {/* Editable Research (single box, under links) */}
                      <div className="space-y-2">
                        <div className="flex items-center justify-between gap-4">
                          <h4 className="font-semibold">Research</h4>
                          <span className={cn(
                            "text-sm",
                            researchRaw.length >= 20000 ? "text-destructive font-medium" : "text-muted-foreground"
                          )}>
                            {Math.min(researchRaw.length, 20000)}/20000
                          </span>
                        </div>
                        <Textarea
                          value={researchRaw}
                          onChange={(e) => {
                            const next = e.target.value.slice(0, 20000);
                            setResearchRaw(next);
                          }}
                          onBlur={(e) => {
                            applyResearchRawToResearch(e.currentTarget.value);
                          }}
                          className="min-h-[400px]"
                          maxLength={20000}
                          placeholder="Edit research here..."
                        />
                        <p className="text-xs text-muted-foreground">
                        Edit the research content above. Changes are applied when you click out of the box.
                        </p>
                      </div>


                    {isStepActive('research') && (
                      <div className="flex items-center justify-between pt-4 border-t">
                        <Button variant="outline" onClick={goToPreviousStep}>
                          <ChevronLeft className="w-4 h-4 mr-2" />
                          Back
                        </Button>
                        <Button onClick={handleResearchContinue}>
                          Next: Components
                          <ChevronRight className="w-4 h-4 ml-2" />
                        </Button>
                      </div>
                    )}
                  </>
                ) : (
                  <div className="text-center py-8 text-muted-foreground">
                    <p className="text-sm">Complete the topic step to see research results</p>
                  </div>
                )}
              </CardContent>
            </Card>
            </div>
          </div>

          {/* Step 3: Components */}
          <div className="w-full flex-shrink-0 px-1">
            <div className="max-w-4xl mx-auto">
              <Card className={cn(
              "border-2 transition-all",
              isStepActive('components') ? "border-primary/50 shadow-md" : "border-border",
              !isStepEnabled('components') && "opacity-40 pointer-events-none"
            )}>
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <div className={cn(
                      "w-8 h-8 rounded-full flex items-center justify-center",
                      isStepCompleted('components') ? "bg-green-100" : isStepActive('components') ? "bg-primary/10" : "bg-muted"
                    )}>
                      {isStepCompleted('components') ? (
                        <Check className="w-4 h-4 text-green-600" />
                      ) : (
                        <Plus className={cn("w-4 h-4", isStepActive('components') ? "text-primary" : "text-muted-foreground")} />
                      )}
                    </div>
                    <h3 className={cn("font-semibold", !isStepActive('components') && !isStepCompleted('components') && "text-muted-foreground")}>
                      Add components
                    </h3>
                  </div>
                </div>
                <p className={cn("text-sm mt-1 ml-10", !isStepActive('components') && !isStepCompleted('components') && "text-muted-foreground/70")}>
                  Components can be hooks, CTAs, outros, and more from your library
                </p>
              </CardHeader>
              <CardContent className="space-y-4">
                {/* Selected components */}
                {selectedComponents.length > 0 && (
                  <div className="space-y-3">
                    {selectedComponents.map((component) => (
                      <div 
                        key={component.id}
                        className="p-4 bg-muted/50 rounded-lg border"
                      >
                        <div className="flex items-start justify-between gap-4">
                          <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 mb-2">
                              <Badge variant="outline" className="text-xs">
                                {componentTypeConfig[component.type as ComponentType]?.label || component.type}
                              </Badge>
                              <h4 className="font-medium truncate">{component.title}</h4>
                            </div>
                            <p className="text-sm text-muted-foreground line-clamp-3">
                              {component.body}
                            </p>
                          </div>
                          {isStepActive('components') && (
                            <Button 
                              variant="ghost" 
                              size="icon"
                              className="h-8 w-8 shrink-0"
                              onClick={() => handleRemoveComponent(component.id)}
                            >
                              <X className="w-4 h-4" />
                            </Button>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}

                {isStepActive('components') ? (
                  <>
                    {/* Add component button */}
                    <Button 
                      variant="outline" 
                      className="w-full border-dashed"
                      onClick={() => setIsComponentSelectorOpen(true)}
                    >
                      <Plus className="w-4 h-4 mr-2" />
                      Add Component
                    </Button>

                    <div className="flex items-center justify-between pt-4 border-t">
                      <Button variant="outline" onClick={goToPreviousStep}>
                        <ChevronLeft className="w-4 h-4 mr-2" />
                        Back
                      </Button>
                      <Button 
                        onClick={handleComponentsContinue}
                      >
                        Next: Script
                        <ChevronRight className="w-4 h-4 ml-2" />
                      </Button>
                    </div>
                  </>
                ) : (
                  <div className="text-center py-8 text-muted-foreground">
                    <p className="text-sm">Complete the research step to add components</p>
                  </div>
                )}
              </CardContent>
            </Card>
            </div>
          </div>

          {/* Step 4: Script */}
          <div className="w-full flex-shrink-0 px-1">
            <div className="max-w-4xl mx-auto">
              <Card className={cn(
              "border-2 transition-all",
              isStepActive('script') ? "border-primary/50 shadow-md" : "border-border",
              !isStepEnabled('script') && "opacity-40 pointer-events-none"
            )}>
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <div className={cn(
                      "w-8 h-8 rounded-full flex items-center justify-center",
                      isStepCompleted('script') ? "bg-green-100" : isStepActive('script') ? "bg-primary/10" : "bg-muted"
                    )}>
                      {isStepCompleted('script') ? (
                        <Check className="w-4 h-4 text-green-600" />
                      ) : (
                        <FileText className={cn("w-4 h-4", isStepActive('script') ? "text-primary" : "text-muted-foreground")} />
                      )}
                    </div>
                    <h3 className={cn("font-semibold", !isStepActive('script') && !isStepCompleted('script') && "text-muted-foreground")}>
                      Write the script
                    </h3>
                  </div>
                  {generatedScript && isStepEnabled('script') && (
                    <div className="flex items-center gap-3">
                      <span className="text-sm text-muted-foreground">
                        {wordCount.toLocaleString()} words
                      </span>
                      <div className="flex items-center gap-1">
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={handleCopy} disabled={!isStepActive('script')}>
                          <Copy className="w-4 h-4" />
                        </Button>
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={handleDownload} disabled={!isStepActive('script')}>
                          <Download className="w-4 h-4" />
                        </Button>
                          <AlertDialog>
                            <AlertDialogTrigger asChild>
                              <Button variant="ghost" size="icon" className="h-8 w-8" disabled={!isStepActive('script')} title="Regenerate">
                          <RefreshCw className="w-4 h-4" />
                        </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                              <AlertDialogHeader>
                                <AlertDialogTitle>Regenerate Script?</AlertDialogTitle>
                                <AlertDialogDescription>
                                  This will regenerate the entire script and replace the current content. This action cannot be undone. Are you sure?
                                </AlertDialogDescription>
                              </AlertDialogHeader>
                              <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <AlertDialogAction onClick={generateScript}>Regenerate</AlertDialogAction>
                              </AlertDialogFooter>
                            </AlertDialogContent>
                          </AlertDialog>

                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8"
                            onClick={handleOpenHistory}
                            disabled={!isStepActive('script')}
                            title="Version History"
                          >
                            <History className="w-4 h-4" />
                          </Button>
                      </div>
                    </div>
                  )}
                </div>
              </CardHeader>
              <CardContent>
                {isGeneratingScript ? (
                  <div className="flex items-center justify-center py-12">
                    <div className="text-center">
                      <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4 text-primary" />
                      <p className="text-muted-foreground">Generating your script...</p>
                    </div>
                  </div>
                ) : generatedScript ? (
                  <div className="space-y-4">
                      {/* Editor Controls / Status */}
                      <div className="flex justify-between items-center text-xs text-muted-foreground px-1">
                        <span>
                          {isAutoSaving ? (
                            <span className="flex items-center text-primary">
                              <Loader2 className="w-3 h-3 mr-1 animate-spin" />
                              Saving...
                            </span>
                          ) : scriptContent !== lastSavedContent ? (
                            <span className="text-amber-500">Unsaved changes</span>
                          ) : (
                            <span className="flex items-center text-green-600">
                              <Check className="w-3 h-3 mr-1" />
                              Saved
                            </span>
                          )}
                        </span>
                      </div>

                    <Textarea
                      value={scriptContent}
                      onChange={(e) => setScriptContent(e.target.value)}
                      className="min-h-[400px] font-mono text-sm leading-relaxed"
                      placeholder="Script content will appear here..."
                      disabled={!isStepActive('script')}
                    />

                      {/* Refinement Input */}
                      {isStepActive('script') && (
                        <div className="p-4 bg-muted/30 rounded-lg border space-y-3">
                          <Label htmlFor="refine-prompt">Update Script Prompt</Label>
                          <div className="flex gap-3">
                            <Textarea
                              id="refine-prompt"
                              placeholder="e.g. Make the intro more punchy, add more statistics, or change the tone to be more casual..."
                              value={refinePrompt}
                              onChange={(e) => setRefinePrompt(e.target.value)}
                              className="min-h-[80px] flex-1"
                            />
                            <Button
                              className="self-end"
                              onClick={handleRefine}
                              disabled={isRefining || !refinePrompt.trim()}
                            >
                              {isRefining ? (
                                <>
                                  <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                                  Updating...
                                </>
                              ) : (
                                <>
                                  <Sparkles className="w-4 h-4 mr-2" />
                                  Update
                                </>
                              )}
                            </Button>
                          </div>
                        </div>
                      )}
                    
                    {/* Script edit actions */}
                    {isStepActive('script') && (
                      <>
                        <div className="flex flex-wrap gap-2 pt-2 border-t">
                            {/* <Button variant="outline" size="sm">
                            <Sparkles className="w-4 h-4 mr-2" />
                            Facts
                          </Button>
                          <Button variant="outline" size="sm">
                            <Sparkles className="w-4 h-4 mr-2" />
                            Hooks
                          </Button>
                          <Button variant="outline" size="sm">
                            <Sparkles className="w-4 h-4 mr-2" />
                            Length
                          </Button>
                          <Button variant="outline" size="sm">
                            <Sparkles className="w-4 h-4 mr-2" />
                            Translate
                          </Button>
                          <Button variant="outline" size="sm">
                            <Sparkles className="w-4 h-4 mr-2" />
                            Restart
                            </Button> */}
                          <div className="flex-1" />
                            {/* <Button onClick={handleManualSave} disabled={isSaving}>
                              {isSaving ? (
                                <>
                                  <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                                  Saving...
                                </>
                              ) : "Save"}
                            </Button> */}
                        </div>

                        <p className="text-xs text-muted-foreground text-center">
                          💡 Tip: Made changes to the research, hook, or style? Have the system do a clean rewrite to incorporate your changes.
                        </p>
                      </>
                    )}
                  </div>
                ) : (
                  <div className="text-center py-12 text-muted-foreground">
                    <p className="text-sm">Complete the previous steps to generate your script</p>
                  </div>
                )}
              </CardContent>
            </Card>
            </div>
          </div>
        </div>
      </div>

      {/* Component Selector Modal */}
      <ComponentSelector
        open={isComponentSelectorOpen}
        onOpenChange={setIsComponentSelectorOpen}
        onSelectComponent={handleAddComponent}
        selectedComponentIds={selectedComponents.map(c => String(c.id))}
      />

      {/* History Dialog */}
      <AlertDialog open={isHistoryOpen} onOpenChange={(open) => {
        setIsHistoryOpen(open);
        if (!open) setViewingVersion(null);
      }}>
        <AlertDialogContent className="max-w-2xl max-h-[80vh] flex flex-col">
          <AlertDialogHeader>
            <div className="flex items-center justify-between">
              <AlertDialogTitle>
                {viewingVersion ? "Version Details" : "Version History"}
              </AlertDialogTitle>
              {viewingVersion && (
                <Button variant="ghost" size="sm" onClick={() => setViewingVersion(null)}>
                  <ArrowLeft className="w-4 h-4 mr-2" />
                  Back to List
                </Button>
              )}
            </div>
            <AlertDialogDescription>
              {viewingVersion
                ? `Viewing version from ${formatDate(viewingVersion.created_at)}`
                : "View and restore previous versions of your script."
              }
            </AlertDialogDescription>
          </AlertDialogHeader>

          <div className="flex-1 overflow-y-auto space-y-4 pr-2">
            {viewingVersion ? (
              <div className="space-y-4">
                <div className="p-4 bg-muted/30 rounded-lg border font-mono text-sm whitespace-pre-wrap">
                  {viewingVersion.content}
                </div>
                <div className="flex justify-end">
                  <Button onClick={() => handleRestoreVersion(viewingVersion)}>
                    Restore This Version
                  </Button>
                </div>
              </div>
            ) : scriptVersions.length === 0 ? (
              <p className="text-center py-8 text-muted-foreground">No history available yet.</p>
            ) : (
              scriptVersions.map((version) => (
                <div key={version.id} className="border rounded-lg p-4 bg-muted/20 hover:bg-muted/40 transition-colors">
                  <div className="flex justify-between items-start mb-2">
                    <div>
                      <span className="font-medium text-sm">
                        {formatDate(version.created_at)}
                      </span>
                    </div>
                    <div className="flex items-center gap-2">
                      <Button size="sm" variant="ghost" onClick={() => setViewingVersion(version)}>
                        <Eye className="w-4 h-4 mr-2" />
                        View
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => handleRestoreVersion(version)}>
                        Restore
                      </Button>
                    </div>
                  </div>
                  <div
                    className="bg-background border rounded px-3 py-2 text-xs font-mono text-muted-foreground line-clamp-3 cursor-pointer hover:border-primary/50 transition-colors"
                    onClick={() => setViewingVersion(version)}
                  >
                    {version.content_preview}
                  </div>
                </div>
              ))
            )}
          </div>

          <AlertDialogFooter>
            <AlertDialogCancel onClick={() => setIsHistoryOpen(false)}>Close</AlertDialogCancel>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Restore Confirmation Dialog */}
      <AlertDialog open={!!versionToRestore} onOpenChange={(open) => !open && setVersionToRestore(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Restore Version?</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to restore this version?
              {scriptContent !== lastSavedContent && (
                <span className="block mt-2 font-medium text-amber-600">
                  Your current unsaved changes will be automatically saved as a new version before restoring.
                </span>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={confirmRestore}>Restore</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};

export default ScriptWizard;
