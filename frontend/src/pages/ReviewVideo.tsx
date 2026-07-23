import { useState, useEffect, useRef, useCallback } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { ArrowLeft, Loader2, Youtube } from "lucide-react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { viewsMaxApi } from "@/lib/api-service";
import { useAuth } from "@/hooks/useAuth";
import { youtubeAuthService } from "@/lib/youtube-auth";
import ScoreDisplay from "@/components/ScoreDisplay";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";

// Import score icons - Title scores
import freshnessIcon from "@/assets/icons/freshness.svg";
import clarityIcon from "@/assets/icons/clarity.svg";
import stakesIcon from "@/assets/icons/stakes.svg";
import curiosityGapIcon from "@/assets/icons/curiosity-gap.svg";
import emotionalTriggerIcon from "@/assets/icons/emotional-trigger.svg";
import concretenessIcon from "@/assets/icons/concreteness.svg";
import humanElementIcon from "@/assets/icons/human-element.svg";
import scaleIcon from "@/assets/icons/scale.svg";
import visualizabilityIcon from "@/assets/icons/glasses 2.svg";
import specificityIcon from "@/assets/icons/specificity.svg";
import noClevernessIcon from "@/assets/icons/no-cleverness.svg";

// Import score icons - Thumbnail scores
import faceDetectionIcon from "@/assets/icons/face-detection.svg";
import contrastIcon from "@/assets/icons/contrast.svg";
import brightnessIcon from "@/assets/icons/brightness.svg";
import saturationIcon from "@/assets/icons/saturation.svg";
import textReadabilityIcon from "@/assets/icons/text-readability.svg";
import visualAppealIcon from "@/assets/icons/visual-appeal.svg";
import compositionIcon from "@/assets/icons/composition.svg";
import sharpnessIcon from "@/assets/icons/sharpness.svg";
import clutterIcon from "@/assets/icons/clutter.svg";
import colorVibranceIcon from "@/assets/icons/color-vibrance.svg";
import dimensionsIcon from "@/assets/icons/dimensions.svg";
import fileSizeIcon from "@/assets/icons/file-size.svg";
import ruleOfThirdsIcon from "@/assets/icons/rule-of-thirds.svg";
import noiseIcon from "@/assets/icons/noise.svg";
import fileFormatIcon from "@/assets/icons/file-format.svg";
import safetyIcon from "@/assets/icons/safety.svg";
import brandConsistencyIcon from "@/assets/icons/brand-consistency.svg";
import objectCountingIcon from "@/assets/icons/object-counting.svg";
import titleThumbnailAlignmentIcon from "@/assets/icons/title-thumbnail-alignment.svg";
import expressionAnalysisIcon from "@/assets/icons/expression-analysis.svg";

interface ReviewData {
  videoId: string;
  videoTitle: string;
  review: string;
  videoData: {
    title: string;
    description: string;
    views: string;
    likes: string;
    publishedAt: string;
    duration: string;
    tags: string[];
  };
  timestamp: string;
}

interface AnalyzerStatus {
  id: number;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  scores?: any;
  error_message?: string;
}

interface VideoData {
  id: number;
  title: string;
  youtube_video_id?: string;
  description?: string;
  published_at?: string;
  view_count?: number;
  like_count?: number;
  formatted_duration?: string;
}

// Temperature bar colors from 1-10 (cold blue to hot red)
const TEMPERATURE_COLORS = [
  '#00224E', // 1
  '#103B73', // 2
  '#0057FF', // 3
  '#00A3FF', // 4
  '#00E5FF', // 5
  '#FFF200', // 6
  '#FFC400', // 7
  '#FF8A00', // 8
  '#FF4D00', // 9
  '#E10600', // 10
];

// Map score keys to icons
const SCORE_ICONS: Record<string, string> = {
  // Title score icons
  freshness: freshnessIcon,
  clarity: clarityIcon,
  stakes: stakesIcon,
  curiosity_gap: curiosityGapIcon,
  curiosity_score: curiosityGapIcon,
  emotional_trigger: emotionalTriggerIcon,
  emotional_appeal: emotionalTriggerIcon,
  emotional_appeal_score: emotionalTriggerIcon,
  concreteness: concretenessIcon,
  human_element: humanElementIcon,
  scale: scaleIcon,
  visualizability: visualizabilityIcon,
  specificity: specificityIcon,
  no_cleverness: noClevernessIcon,
  
  // Thumbnail score icons
  face_detection: faceDetectionIcon,
  face_score: faceDetectionIcon,
  expression_analysis: expressionAnalysisIcon,
  contrast: contrastIcon,
  contrast_score: contrastIcon,
  histogram_contrast: contrastIcon,
  brightness: brightnessIcon,
  saturation: saturationIcon,
  text_readability: textReadabilityIcon,
  readability_check: textReadabilityIcon,
  text_overlay: textReadabilityIcon,
  text_detection_count: textReadabilityIcon,
  visual_appeal: visualAppealIcon,
  visual_appeal_score: visualAppealIcon,
  engagement_potential: visualAppealIcon,
  composition_score: compositionIcon,
  title_thumbnail_alignment: titleThumbnailAlignmentIcon,
  sharpness: sharpnessIcon,
  thumbnail_clarity: sharpnessIcon,
  clutter_score: clutterIcon,
  color_vibrance: colorVibranceIcon,
  story_clarity_estimation: clarityIcon,
  curiosity_gap_estimation: curiosityGapIcon,
  
  // New unique icons for previously missing scores
  object_counting: objectCountingIcon,
  dimensions: dimensionsIcon,
  file_size: fileSizeIcon,
  file_format: fileFormatIcon,
  rule_of_thirds: ruleOfThirdsIcon,
  noise: noiseIcon,
  safety_classification: safetyIcon,
  brand_consistency: brandConsistencyIcon,
};

const ReviewVideo = () => {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const { session } = useAuth();
  const [reviewData, setReviewData] = useState<ReviewData | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isGenerating, setIsGenerating] = useState(false);
  const [video, setVideo] = useState<VideoData | null>(null);
  const [titleAnalyzer, setTitleAnalyzer] = useState<AnalyzerStatus | null>(null);
  const [thumbnailAnalyzer, setThumbnailAnalyzer] = useState<AnalyzerStatus | null>(null);
  const pollingIntervalRef = useRef<NodeJS.Timeout | null>(null);
  const reviewStartedRef = useRef<boolean>(false);
  const previousIdRef = useRef<string | undefined>(undefined);
  const isMountedRef = useRef<boolean>(false);
  const titleAnalyzerRef = useRef<AnalyzerStatus | null>(null);
  const thumbnailAnalyzerRef = useRef<AnalyzerStatus | null>(null);

  // Stop polling interval
  const stopPolling = useCallback(() => {
    if (pollingIntervalRef.current) {
      clearInterval(pollingIntervalRef.current);
      pollingIntervalRef.current = null;
    }
  }, []);

  // Start polling interval with specific analyzer data
  const startPollingWithData = useCallback((titleAnalyzerData: any, thumbnailAnalyzerData: any) => {
    // Clear any existing interval first
    stopPolling();

    // Don't start if already polling
    if (pollingIntervalRef.current) {
      return;
    }

    // Initialize refs with the provided data
    if (titleAnalyzerData?.id > 0) {
      titleAnalyzerRef.current = {
        id: titleAnalyzerData.id,
        status: titleAnalyzerData.status || 'processing'
      };
    }
    if (thumbnailAnalyzerData?.id > 0) {
      thumbnailAnalyzerRef.current = {
        id: thumbnailAnalyzerData.id,
        status: thumbnailAnalyzerData.status || 'processing'
      };
    }

    // Poll immediately with the provided data
    const pollWithData = async () => {
      try {
        const token = session?.token || null;
        if (!token) {
          return;
        }

        let shouldContinuePolling = false;

        // Poll title analyzer if we have a valid ID and it's still processing
        const currentTitle = titleAnalyzerRef.current;
        if (currentTitle && currentTitle.id > 0 && (currentTitle.status === 'processing' || currentTitle.status === 'pending')) {
          const titleResult = await viewsMaxApi.getTitleAnalyzerStatus(token, currentTitle.id);
          if (titleResult.success && titleResult.data) {
            const currentStatus = titleResult.data.status as AnalyzerStatus['status'];

            const updatedTitle: AnalyzerStatus = {
              id: titleResult.data.id,
              status: currentStatus,
              scores: titleResult.data.scores,
              error_message: titleResult.data.error_message
            };

            // Update ref
            titleAnalyzerRef.current = updatedTitle;

            // Update state
            setTitleAnalyzer(updatedTitle);

            if (currentStatus === 'processing' || currentStatus === 'pending') {
              shouldContinuePolling = true;
            }
          }
        }

        // Poll thumbnail analyzer if we have a valid ID and it's still processing
        const currentThumbnail = thumbnailAnalyzerRef.current;
        if (currentThumbnail && currentThumbnail.id > 0 && (currentThumbnail.status === 'processing' || currentThumbnail.status === 'pending')) {
          const thumbnailResult = await viewsMaxApi.getThumbnailAnalyzerStatus(token, currentThumbnail.id);
          if (thumbnailResult.success && thumbnailResult.data) {
            const currentStatus = thumbnailResult.data.status as AnalyzerStatus['status'];

            const updatedThumbnail: AnalyzerStatus = {
              id: thumbnailResult.data.id,
              status: currentStatus,
              scores: thumbnailResult.data.scores,
              error_message: thumbnailResult.data.error_message
            };

            // Update ref
            thumbnailAnalyzerRef.current = updatedThumbnail;

            // Update state
            setThumbnailAnalyzer(updatedThumbnail);

            if (currentStatus === 'processing' || currentStatus === 'pending') {
              shouldContinuePolling = true;
            }
          }
        }

        // Stop polling if no analyzers are processing
        if (!shouldContinuePolling) {
          stopPolling();
        }
      } catch (error: any) {
        console.error('Error polling review status:', error);
      }
    };

    // Poll immediately
    pollWithData();

    // Then poll every 2 seconds
    pollingIntervalRef.current = setInterval(() => {
      pollWithData();
    }, 2000);
  }, [stopPolling, session]);

  const loadReviewData = useCallback(async () => {
    if (!id || reviewStartedRef.current) return;

    reviewStartedRef.current = true;
    setIsLoading(true);
    setIsGenerating(true);
    
    try {
      const token = session?.token || null;
      if (!token) {
        throw new Error('Authentication required');
      }

      // POST to api/videos/$id/review
      const videoId = parseInt(id, 10);
      if (isNaN(videoId)) {
        throw new Error('Invalid video ID');
      }

      const result = await viewsMaxApi.reviewVideoById(token, videoId);

      let currentVideo: VideoData | null = null;

      if (result.success && result.data) {
        const data = result.data;

        // Set video data from review response 
        if (data.video) {
          currentVideo = {
            id: data.video.id,
            title: data.video.title || `Video ${id}`,
            youtube_video_id: data.video.youtube_video_id,
            description: data.video.description,
            published_at: data.video.published_at,
            view_count: data.video.view_count,
            like_count: data.video.like_count,
            formatted_duration: data.video.formatted_duration
          };
          setVideo(currentVideo);
        }

        // Initialize analyzers - always show loading state initially
        if (data.title_analyzer && data.title_analyzer.id > 0) {
          const titleAnalyzerState: AnalyzerStatus = {
            id: data.title_analyzer.id,
            status: data.title_analyzer.status || 'processing',
            scores: (data.title_analyzer as any).scores,
            error_message: (data.title_analyzer as any).error_message
          };
          titleAnalyzerRef.current = titleAnalyzerState;
          setTitleAnalyzer(titleAnalyzerState);
        } else {
          // If no title analyzer returned, set to processing to show loading
          const titleAnalyzerState: AnalyzerStatus = {
            id: 0,
            status: 'processing'
          };
          titleAnalyzerRef.current = titleAnalyzerState;
          setTitleAnalyzer(titleAnalyzerState);
        }

        if (data.thumbnail_analyzer && data.thumbnail_analyzer.id > 0) {
          const thumbnailAnalyzerState: AnalyzerStatus = {
            id: data.thumbnail_analyzer.id,
            status: data.thumbnail_analyzer.status || 'processing',
            scores: (data.thumbnail_analyzer as any).scores,
            error_message: (data.thumbnail_analyzer as any).error_message
          };
          thumbnailAnalyzerRef.current = thumbnailAnalyzerState;
          setThumbnailAnalyzer(thumbnailAnalyzerState);
        } else {
          // If no thumbnail analyzer returned, set to processing to show loading
          const thumbnailAnalyzerState: AnalyzerStatus = {
            id: 0,
            status: 'processing'
          };
          thumbnailAnalyzerRef.current = thumbnailAnalyzerState;
          setThumbnailAnalyzer(thumbnailAnalyzerState);
        }

        // Start polling if we have analyzer IDs and they're still processing
        const titleAnalyzerData = data.title_analyzer || { id: 0, status: 'processing' };
        const thumbnailAnalyzerData = data.thumbnail_analyzer || { id: 0, status: 'processing' };
        
        if (
          (titleAnalyzerData.id > 0 && (titleAnalyzerData.status === 'processing' || titleAnalyzerData.status === 'pending')) ||
          (thumbnailAnalyzerData.id > 0 && (thumbnailAnalyzerData.status === 'processing' || thumbnailAnalyzerData.status === 'pending'))
        ) {
          // Pass analyzer data directly to avoid stale closure issues
          startPollingWithData(titleAnalyzerData, thumbnailAnalyzerData);
        }
      } else {
        throw new Error(result.error || 'Failed to start review');
      }

      // Set review data using the video we fetched
      if (currentVideo) {
        setReviewData({
          videoId: currentVideo.youtube_video_id || id,
          videoTitle: currentVideo.title || `Video ${id}`,
          review: '', // This might need to be fetched from another endpoint
          videoData: {
            title: currentVideo.title || `Video ${id}`,
            description: currentVideo.description || '',
            views: currentVideo.view_count?.toLocaleString() || '0',
            likes: currentVideo.like_count?.toLocaleString() || '0',
            publishedAt: currentVideo.published_at || new Date().toISOString(),
            duration: currentVideo.formatted_duration || '',
            tags: []
          },
          timestamp: new Date().toISOString()
        });
      } else {
        throw new Error(result.error || 'Failed to load review');
      }
    } catch (error: any) {
      console.error('Error loading review:', error);
      toast.error(`Failed to load review: ${error.message}`);
      reviewStartedRef.current = false;
    } finally {
      setIsLoading(false);
      setIsGenerating(false);
    }
  }, [id, startPollingWithData, session]);

  useEffect(() => {
    // Check if this is the first mount
    const isFirstMount = !isMountedRef.current;
    if (isFirstMount) {
      isMountedRef.current = true;
    }

    // Check if ID changed
    const idChanged = previousIdRef.current !== id;
    if (idChanged) {
      // Stop polling when ID changes
      stopPolling();
      // Reset refs when id changes
      reviewStartedRef.current = false;
      previousIdRef.current = id;
      // Reset analyzer refs
      titleAnalyzerRef.current = null;
      thumbnailAnalyzerRef.current = null;
      // Reset analyzer states
      setTitleAnalyzer(null);
      setThumbnailAnalyzer(null);
    }

    // Load review data if we have an ID and haven't started yet
    if (id && !reviewStartedRef.current) {
      loadReviewData();
    }

    // Cleanup on unmount only
    return () => {
      if (isMountedRef.current) {
        isMountedRef.current = false;
        stopPolling();
      }
    };
  }, [id, stopPolling, loadReviewData]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="text-center">
          <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4 text-primary" />
          <p className="text-muted-foreground">
            {isGenerating ? 'Generating review...' : 'Loading review...'}
          </p>
        </div>
      </div>
    );
  }

  if (!reviewData) {
    return (
      <div className="space-y-6">
        <Button
          variant="ghost"
          onClick={() => navigate('/dashboard/review')}
          className="mb-4"
        >
          <ArrowLeft className="w-4 h-4 mr-2" />
          Back to Reviews
        </Button>
        <Card>
          <CardContent className="p-8 text-center">
            <p className="text-muted-foreground">Review not found</p>
          </CardContent>
        </Card>
      </div>
    );
  }

  // Parse the review text into sections (basic parsing)
  const parseReview = (reviewText: string) => {
    const sections: { title: string; content: string }[] = [];
    const lines = reviewText.split('\n').filter(line => line.trim());
    
    let currentSection: { title: string; content: string } | null = null;
    
    lines.forEach(line => {
      const trimmedLine = line.trim();
      // Check if line looks like a section header (starts with number, has colon, or is all caps)
      if (trimmedLine.match(/^\d+\.|^[A-Z][^a-z]*:|^[A-Z\s]{3,}$/)) {
        if (currentSection) {
          sections.push(currentSection);
        }
        currentSection = { title: trimmedLine, content: '' };
      } else if (currentSection) {
        currentSection.content += (currentSection.content ? '\n' : '') + trimmedLine;
      } else {
        // If no section started yet, create one
        if (!currentSection) {
          currentSection = { title: 'Review', content: '' };
        }
        currentSection.content += (currentSection.content ? '\n' : '') + trimmedLine;
      }
    });
    
    if (currentSection) {
      sections.push(currentSection);
    }
    
    return sections.length > 0 ? sections : [{ title: 'Review', content: reviewText }];
  };

  const reviewSections = reviewData ? parseReview(reviewData.review) : [];

  // Map of score keys to descriptive lines
  const scoreDescriptions: Record<string, string> = {
    // Readability scores
    'freshness': 'How often you publish and how fresh your content feels. Higher scores mean consistent uploads and timely, relevant topics.',
    'fre': 'Flesch Reading Ease score, a metric that indicates how easy a text is to understand',
    'flesch_reading_ease': 'Flesch Reading Ease score, a metric that indicates how easy a text is to understand',
    'flesch_reading_ease_score': 'Flesch Reading Ease score, a metric that indicates how easy a text is to understand',
    'flesch_kincaid_grade_level': 'Flesch-Kincaid Grade Level, representing the U.S. school grade level required to comprehend the text',
    'flesch_kincaid_grade': 'Flesch-Kincaid Grade Level, representing the U.S. school grade level required to comprehend the text',
    'gunning_fog_index': 'Gunning Fog Index, estimating the years of formal education needed to understand the text on a first reading',
    'gunning_fog_score': 'Gunning Fog Index, estimating the years of formal education needed to understand the text on a first reading',
    'smog_index': 'SMOG Index, measuring the number of years of education needed to comprehend the text',
    'smog_grade': 'SMOG Index, measuring the number of years of education needed to comprehend the text',
    'coleman_liau_index': 'Coleman-Liau Index, assessing readability based on characters per word and words per sentence',
    'automated_readability_index': 'Automated Readability Index (ARI), calculating the U.S. grade level needed to understand the text',
    'ari': 'Automated Readability Index (ARI), calculating the U.S. grade level needed to understand the text',
    
    // Title-specific scores
    'clarity': 'Clarity score, measuring how clear and easy to understand the title is for viewers',
    'stakes': 'Stakes score, evaluating how well the title communicates what viewers stand to gain or lose',
    'curiosity_gap': 'Curiosity Gap score, measuring how well the title creates intrigue and encourages clicks',
    'curiosity_score': 'Curiosity Gap score, measuring how well the title creates intrigue and encourages clicks',
    'emotional_trigger': 'Emotional Trigger score, evaluating how effectively the title triggers emotional responses in viewers',
    'emotional_appeal': 'Emotional Appeal score, evaluating how well the title triggers emotional responses in viewers',
    'emotional_appeal_score': 'Emotional Appeal score, evaluating how well the title triggers emotional responses in viewers',
    'concreteness': 'Concreteness score, measuring how specific and tangible the title is rather than abstract',
    'human_element': 'Human Element score, evaluating how well the title incorporates personal or relatable human aspects',
    'scale': 'Scale score, measuring how well the title communicates the magnitude or scope of the content',
    'visualizability': 'Visualizability score, evaluating how easy it is for viewers to visualize what the video is about',
    'specificity': 'Specificity score, measuring how specific and detailed the title is rather than vague or generic',
    'no_cleverness': 'No Cleverness score, evaluating how straightforward and clear the title is without being overly clever or confusing',
    'clickability': 'Clickability score, measuring how likely viewers are to click on a video based on the title',
    'clickability_score': 'Clickability score, measuring how likely viewers are to click on a video based on the title',
    'seo_score': 'SEO Score, measuring how well the title is optimized for search engine visibility and discoverability',
    'keyword_density': 'Keyword Density score, evaluating how effectively keywords are used in the title',
    'keyword_optimization': 'Keyword Optimization score, measuring how well the title incorporates relevant search terms',
    'length_score': 'Length Score, assessing whether the title length is optimal for YouTube\'s display and search algorithms',
    'title_length': 'Title Length score, evaluating if the title is the right length for maximum visibility and engagement',
    'power_words': 'Power Words score, evaluating the use of compelling and action-oriented words in the title',
    'power_words_score': 'Power Words score, evaluating the use of compelling and action-oriented words in the title',
    'numbers_inclusion': 'Numbers Inclusion score, assessing whether including numbers improves the title\'s effectiveness',
    'question_format': 'Question Format score, evaluating if using a question format makes the title more engaging',
    'urgency_score': 'Urgency Score, measuring how well the title creates a sense of urgency or timeliness',
    
    // Thumbnail-specific scores
    'face_detection': 'Face Detection score, assessing whether faces in the thumbnail improve engagement',
    'face_score': 'Face Detection score, assessing whether faces in the thumbnail improve engagement',
    'expression_analysis': 'Expression Analysis score, evaluating the emotional expression of faces in the thumbnail',
    'object_counting': 'Object Counting score, measuring the number of distinct objects and visual elements in the thumbnail',
    'contrast': 'Contrast score, evaluating the color contrast and visual clarity of the thumbnail',
    'contrast_score': 'Contrast Score, evaluating the color contrast and visual clarity of the thumbnail',
    'brightness': 'Brightness score, measuring the overall brightness level and exposure of the thumbnail',
    'saturation': 'Saturation score, evaluating the color intensity and vibrancy of the thumbnail',
    'clutter_score': 'Clutter Score, assessing how clean and uncluttered the thumbnail composition is',
    'readability_check': 'Readability Check score, measuring how easy it is to read any text overlaid on the thumbnail',
    'text_readability': 'Text Readability score, measuring how easy it is to read any text overlaid on the thumbnail',
    'curiosity_gap_estimation': 'Curiosity Gap Estimation score, measuring how well the thumbnail creates intrigue and encourages clicks',
    'story_clarity_estimation': 'Story Clarity Estimation score, evaluating how clearly the thumbnail communicates the video\'s story or topic',
    'title_thumbnail_alignment': 'Title Thumbnail Alignment score, measuring how well the thumbnail complements and aligns with the video title',
    'safety_classification': 'Safety Classification score, assessing whether the thumbnail content is appropriate and safe for all audiences',
    'dimensions': 'Dimensions score, evaluating if the thumbnail dimensions meet YouTube\'s optimal size requirements',
    'file_format': 'File Format score, assessing whether the thumbnail uses an optimal file format for quality and performance',
    'file_size': 'File Size score, measuring if the thumbnail file size is optimized for fast loading without quality loss',
    'histogram_contrast': 'Histogram Contrast score, evaluating the tonal distribution and contrast based on image histogram analysis',
    'sharpness': 'Sharpness score, measuring the clarity and sharpness of the thumbnail image',
    'noise': 'Noise score, assessing the amount of visual noise or grain in the thumbnail image',
    'rule_of_thirds': 'Rule of Thirds score, evaluating how well the thumbnail composition follows the rule of thirds for visual balance',
    'text_detection_count': 'Text Detection Count score, measuring the number of text elements detected in the thumbnail',
    'visual_appeal': 'Visual Appeal score, measuring how attractive and eye-catching the thumbnail is',
    'visual_appeal_score': 'Visual Appeal score, measuring how attractive and eye-catching the thumbnail is',
    'color_vibrance': 'Color Vibrance score, measuring how vibrant and attention-grabbing the thumbnail colors are',
    'composition_score': 'Composition Score, evaluating the overall layout and visual balance of the thumbnail',
    'brand_consistency': 'Brand Consistency score, measuring how well the thumbnail matches the channel\'s visual style',
    'text_overlay': 'Text Overlay score, evaluating the effectiveness of any text added to the thumbnail',
    'thumbnail_clarity': 'Thumbnail Clarity score, measuring the sharpness and quality of the thumbnail image',
    'engagement_potential': 'Engagement Potential score, assessing how likely the thumbnail is to drive clicks and views',
    
    // General scores
    'overall_score': 'Overall Score, a comprehensive evaluation of the content\'s effectiveness',
    'avg_score': 'Average Score, the mean of all individual score metrics',
    'total_score': 'Total Score, the sum of all individual score metrics',
  };

  // Helper function to format score names (snake_case to Title Case)
  const formatScoreName = (name: string): string => {
    return name
      .split('_')
      .map(word => word.charAt(0).toUpperCase() + word.slice(1))
      .join(' ');
  };

  // Get descriptive line for a score key
  const getScoreDescription = (key: string): string => {
    return scoreDescriptions[key.toLowerCase()] || formatScoreName(key);
  };

  // Temperature Bar Component
  const TemperatureBar = ({ score }: { score: number }) => {
    // Round score to nearest integer for bar display (1-10)
    const roundedScore = Math.max(1, Math.min(10, Math.round(score)));
    
    return (
      <div className="flex gap-[1px]">
        {TEMPERATURE_COLORS.map((color, index) => (
          <div
            key={index}
            className="w-[10px] h-[21px] rounded-[3px]"
            style={{
              backgroundColor: index < roundedScore ? color : '#E5E7EB',
            }}
          />
        ))}
      </div>
    );
  };

  // Individual Score Item Component
  const ScoreItem = ({ scoreKey, value }: { scoreKey: string; value: number }) => {
    const icon = SCORE_ICONS[scoreKey.toLowerCase()] || freshnessIcon;
    const tooltip = getScoreDescription(scoreKey);
    
    return (
      <div 
        className="flex items-center gap-[8px] rounded-[8px] border border-border bg-background"
        style={{ padding: '6px 16px 6px 8px' }}
      >
        {/* Icon */}
        <div className="flex-shrink-0 w-[36px] h-[36px] flex justify-center items-center">
          <img src={icon} alt={scoreKey} className="w-[18px] h-[18px]" />
        </div>
        
        {/* Score Name and Value */}
        <div className="flex-grow min-w-0">
          <div className="flex items-center gap-2">
            <span className="font-semibold text-foreground">{formatScoreName(scoreKey)}</span>
          </div>
          <div className="text-sm font-semibold text-muted-foreground">{Math.round(value)}/10</div>
        </div>
        
        {/* Temperature Bar */}
        <div className="flex-shrink-0 ml-auto">
          <TemperatureBar score={value} />
        </div>
        
        {/* Tooltip */}
        <TooltipProvider delayDuration={100}>
          <Tooltip>
            <TooltipTrigger asChild>
              <button 
                className="flex-shrink-0 w-[18px] h-[18px] rounded-full flex items-center justify-center transition-colors ml-[6px]"
                style={{ backgroundColor: '#BEBEBE' }}
                onMouseEnter={(e) => e.currentTarget.style.backgroundColor = '#626A84'}
                onMouseLeave={(e) => e.currentTarget.style.backgroundColor = '#BEBEBE'}
              >
                <span className="text-white font-semibold" style={{ fontSize: '14px', lineHeight: 1 }}>?</span>
              </button>
            </TooltipTrigger>
            <TooltipContent 
              side="top" 
              align="start"
              className="max-w-xs text-white border-none"
              style={{ backgroundColor: '#1D202A' }}
            >
              <p className="text-sm">{tooltip}</p>
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>
    );
  };

  // Component to display scores as a grid
  const ScoreList = ({ scores }: { scores: Record<string, number> }) => {
    // Use avg_score from API response
    const avgScore = scores.avg_score || 0;
    
    // Filter out avg_score from individual score entries
    const scoreEntries = Object.entries(scores).filter(([key]) => key !== 'avg_score');
    
    return (
      <div className="space-y-6">
        {/* Overall Score at the top */}
        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <span className="text-lg font-semibold text-foreground">Overall Score</span>
            <span className="text-lg font-semibold text-muted-foreground">{Math.round(avgScore * 10) / 10}/10</span>
          </div>
          <TemperatureBar score={avgScore} />
        </div>
        
        {/* Score Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-2">
          {scoreEntries.map(([key, value]) => (
            <ScoreItem key={key} scoreKey={key} value={value} />
          ))}
        </div>
      </div>
    );
  };

  return (
    <div className="space-y-6">
      {/* Main Layout */}
      <div className="grid grid-cols-1 xl:grid-cols-3 gap-2">
      {/* <div className="grid grid-cols-1 lg:grid-cols-3 gap-2"> */}
        {/* Review Content - Left Side */}
        <div className="lg:col-span-2">
          <div className="space-y-6">
            <h2 className="text-2xl font-bold text-foreground">Review Analysis</h2>
            
            <Tabs defaultValue="title" className="w-full">
              {/* Underlined Tab Navigation */}
              <div className="border-b border-border">
                <TabsList className="h-auto bg-transparent border-none p-0 rounded-none justify-start w-auto gap-6">
                  <TabsTrigger 
                    value="title" 
                    className="group rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none text-foreground data-[state=active]:text-primary flex items-center gap-2"
                    style={{ padding: '10px 20px' }}
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" className="text-foreground group-data-[state=active]:text-primary">
                      <path d="M12 20V4" stroke="currentColor" strokeWidth="2" strokeLinecap="square"/>
                      <path d="M5 4H19" stroke="currentColor" strokeWidth="2" strokeLinecap="square"/>
                      <path d="M19.9 12.1L19 10L18.1 12.1L16 13L18.1 13.9L19 16L19.9 13.9L22 13L19.9 12.1Z" fill="currentColor"/>
                      <path d="M5.25 9.75L4.5 8L3.75 9.75L2 10.5L3.75 11.25L4.5 13L5.25 11.25L7 10.5L5.25 9.75Z" fill="currentColor"/>
                      <path d="M7.95 17.05L7.5 16L7.05 17.05L6 17.5L7.05 17.95L7.5 19L7.95 17.95L9 17.5L7.95 17.05Z" fill="currentColor"/>
                    </svg>
                    Title Review
                  </TabsTrigger>
                  <TabsTrigger 
                    value="thumbnail" 
                    className="group rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none text-foreground data-[state=active]:text-primary flex items-center gap-2"
                    style={{ padding: '10px 20px' }}
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" className="text-foreground group-data-[state=active]:text-primary">
                      <path d="M11 4H4C2.89543 4 2 4.89543 2 6V18C2 19.1046 2.89543 20 4 20H20C21.1046 20 22 19.1046 22 18V13" stroke="currentColor" strokeWidth="2" strokeMiterlimit="10" strokeLinecap="square"/>
                      <path d="M7 10C7.55228 10 8 9.55228 8 9C8 8.44772 7.55228 8 7 8C6.44772 8 6 8.44772 6 9C6 9.55228 6.44772 10 7 10Z" stroke="currentColor" strokeWidth="2" strokeMiterlimit="10" strokeLinecap="square"/>
                      <path d="M20.5 3.5L19 0L17.5 3.5L14 5L17.5 6.5L19 10L20.5 6.5L24 5L20.5 3.5Z" fill="currentColor"/>
                      <path d="M6 20L13 12L20 20" stroke="currentColor" strokeWidth="2" strokeMiterlimit="10"/>
                    </svg>
                    Thumbnail Review
                  </TabsTrigger>
                </TabsList>
              </div>

              {/* Title Review Tab */}
              <TabsContent value="title" className="mt-6">
                {!titleAnalyzer || titleAnalyzer.status === 'processing' || titleAnalyzer.status === 'pending' ? (
                  <div className="flex items-center justify-center py-12">
                    <div className="text-center">
                      <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4 text-primary" />
                      <p className="text-sm text-muted-foreground">Analyzing title...</p>
                    </div>
                  </div>
                ) : titleAnalyzer.status === 'failed' ? (
                  <div className="p-4 bg-red-50 dark:bg-red-950 rounded-lg">
                    <p className="text-sm text-red-600 dark:text-red-400">
                      {titleAnalyzer.error_message || 'Analysis failed'}
                    </p>
                  </div>
                ) : titleAnalyzer.status === 'completed' && titleAnalyzer.scores ? (
                  <ScoreList scores={titleAnalyzer.scores} />
                ) : (
                  <div className="flex items-center justify-center py-12">
                    <div className="text-center">
                      <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4 text-primary" />
                      <p className="text-sm text-muted-foreground">Starting title analysis...</p>
                    </div>
                  </div>
                )}
              </TabsContent>

              {/* Thumbnail Review Tab */}
              <TabsContent value="thumbnail" className="mt-6 mb-4">
                {!thumbnailAnalyzer || thumbnailAnalyzer.status === 'processing' || thumbnailAnalyzer.status === 'pending' ? (
                  <div className="flex items-center justify-center py-12">
                    <div className="text-center">
                      <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4 text-primary" />
                      <p className="text-sm text-muted-foreground">Analyzing thumbnail...</p>
                    </div>
                  </div>
                ) : thumbnailAnalyzer.status === 'failed' ? (
                  <div className="p-4 bg-red-50 dark:bg-red-950 rounded-lg">
                    <p className="text-sm text-red-600 dark:text-red-400">
                      {thumbnailAnalyzer.error_message || 'Analysis failed'}
                    </p>
                  </div>
                ) : thumbnailAnalyzer.status === 'completed' && thumbnailAnalyzer.scores ? (
                  <ScoreList scores={thumbnailAnalyzer.scores} />
                ) : (
                  <div className="flex items-center justify-center py-12">
                    <div className="text-center">
                      <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4 text-primary" />
                      <p className="text-sm text-muted-foreground">Starting thumbnail analysis...</p>
                    </div>
                  </div>
                )}
              </TabsContent>
            </Tabs>
          </div>
        </div>

        {/* Video Player - Right Side */}
        <div className="space-y-4">
          <Card>
            <CardContent className="p-0">
              <div className="aspect-video">
                <iframe
                  className="w-full h-full rounded-t-lg"
                  src={`https://www.youtube.com/embed/${video?.youtube_video_id || reviewData?.videoId || id}`}
                  title={video?.title || reviewData?.videoTitle || `Video ${id}`}
                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                  allowFullScreen
                />
              </div>
            </CardContent>
          </Card>
          
          <Card>
            <CardContent className="p-4">
              <h3 className="font-semibold text-foreground mb-3 line-clamp-2">
                {video?.title || reviewData?.videoData?.title || reviewData?.videoTitle || `Video ${id}`}
              </h3>
              <Button
                variant="outline"
                className="w-full"
                onClick={() => window.open(`https://youtube.com/watch?v=${video?.youtube_video_id || reviewData?.videoId || id}`, '_blank')}
              >
                <Youtube className="w-4 h-4 mr-2" />
                Watch on YouTube
              </Button>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
};

export default ReviewVideo;

