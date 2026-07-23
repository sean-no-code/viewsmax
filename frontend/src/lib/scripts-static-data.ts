// Static data service for Scripts feature
// This mimics real API responses - when real API is integrated, 
// this static data will be used as fallback on error

export interface ResearchSource {
  id: string;
  name: string;
  url: string;
  favicon?: string;
}

export interface Research {
  executiveSummary: string;
  keyContext: string;
  keyFacts: string[];
  sources: ResearchSource[];
}

export interface ScriptComponent {
  id: string;
  type: 'hook' | 'cta' | 'outro' | 'transition' | 'story';
  title: string;
  body: string;
  tags?: string[];
}

export interface GeneratedScript {
  id: string;
  title: string;
  content: string;
  wordCount: number;
  readingTime: number;
  status: 'pending' | 'processing' | 'completed' | 'failed';
}

// Static Research Data
export const staticResearchData: Research = {
  executiveSummary: `Artificial Intelligence is fundamentally transforming how we work and create. By leveraging AI-powered tools, professionals can automate repetitive tasks, generate high-quality content, and gain insights from vast amounts of data in seconds. Mastering these tools is no longer optional but a core competency for the modern workforce. Focusing on prompt engineering and workflow integration allows creators and businesses to stay competitive in an increasingly automated world.`,
  keyContext: `The AI revolution is comparable to the industrial revolution in its scope of impact. From large language models like GPT-4 to specialized generative tools for video and design, the landscape is evolving daily. Understanding the underlying principles of how these models work—and where they fail—is essential for anyone looking to optimize their personal or professional output.`,
  keyFacts: [
    "Efficiency Gains: AI can reduce time spent on research and drafting by up to 70%.",
    "Prompt Engineering: The quality of AI output is directly proportional to the clarity of the input.",
    "Human-in-the-Loop: AI is a co-pilot, not a replacement; human oversight remains critical.",
    "Data Privacy: Understanding how your data is used by AI models is a top priority.",
    "Tool Proliferation: Thousands of new AI apps are launched monthly, requiring selective adoption.",
    "Bias and Accuracy: AI models can hallucinate or reflect biases present in their training data.",
    "Future of Skills: Creative thinking and complex problem solving are becoming more valuable.",
    "Continuous Learning: The rapid pace of AI development requires a mindset of lifelong learning."
  ],
  sources: [
    { id: '1', name: 'youtube.com', url: 'https://www.youtube.com', favicon: 'https://www.youtube.com/favicon.ico' },
    { id: '2', name: 'medium.com', url: 'https://medium.com', favicon: 'https://medium.com/favicon.ico' },
    { id: '3', name: 'vidiq.com', url: 'https://vidiq.com', favicon: 'https://vidiq.com/favicon.ico' },
    { id: '4', name: 'tubebuddy.com', url: 'https://tubebuddy.com', favicon: 'https://www.tubebuddy.com/favicon.ico' },
    { id: '5', name: 'backlinko.com', url: 'https://backlinko.com', favicon: 'https://backlinko.com/favicon.ico' },
  ]
};

// Static Components Library
export const staticComponentsLibrary: ScriptComponent[] = [
  // Hooks
  {
    id: 'hook-ai-1',
    type: 'hook',
    title: 'The AI Paradox | Beyond the Hype',
    body: `We were promised that AI would give us more free time. Instead, it's just making us faster at doing work that doesn't matter.

Today, I'm breaking down the three "trap" workflows that are actually draining your productivity while making you feel like a genius.

Stop being a prompt engineer, and start being a system architect.`,
    tags: ['ai', 'productivity', 'workflows']
  },
  {
    id: 'hook-remote-1',
    type: 'hook',
    title: 'Remote Work 2.0 | Digital Nomad Reality',
    body: `Everyone shows you the laptop by the beach. Nobody shows you the three hours spent searching for a stable Wi-Fi signal or the isolation of living in a country where you don't speak the language.

I've been travelling for 2 years straight, and the reality of the digital nomad lifestyle is 90% different from what you see on Instagram.

Here is the unglamorous truth about working from anywhere.`,
    tags: ['remote-work', 'lifestyle', 'travel']
  },
  {
    id: 'hook-finance-1',
    type: 'hook',
    title: 'The Mid-Life Pivot | Career Change',
    body: `At 35, I had the perfect corporate job, a pension, and a slowly growing sense of dread every Monday morning. 

Last year, I walked away from all of it to start a business in a field I knew nothing about. 

It was the most terrifying—and best—decision I've ever made. If you're feeling stuck, this is for you.`,
    tags: ['career', 'motivation', 'business']
  },

  // CTAs
  {
    id: 'cta-exclusive-1',
    type: 'cta',
    title: 'VIP Access | The Laboratory',
    body: `I don't post these templates anywhere else. 

If you want the exact JSON files and Notion boards I use to run my entire production house, join 'The Laboratory'. 

The link is pinned in the first comment—access is limited to 100 people this month.`,
    tags: ['exclusive', 'membership', 'templates']
  },
  {
    id: 'cta-newsletter-1',
    type: 'cta',
    title: 'Signal Over Noise | Weekly Digest',
    body: `In a world of infinite scrolls, I spend 20 hours a week curating the only things that actually matter in tech and design.

Get the 'Signal' in your inbox every Sunday. No sponsors, no fluff, just pure high-signal content.

Click the link below to join 12,000 other thinkers.`,
    tags: ['newsletter', 'curation', 'tech']
  },

  // Outros
  {
    id: 'outro-action-1',
    type: 'outro',
    title: 'The 24-Hour Rule | Immediate Action',
    body: `Information without implementation is just entertainment. 

Don't let this be another video you just watch and forget. You have exactly 24 hours to take one small action from this system, or the knowledge will evaporate.

What's it going to be? Update that one doc, or send that one email. Do it now.`,
    tags: ['action', 'productivity', 'closing']
  }
];



const COMPONENT_LIBRARY_STORAGE_KEY = "scripts_component_library_v1";

function safeJsonParse<T>(value: string | null): T | null {
  if (!value) return null;
  try {
    return JSON.parse(value) as T;
  } catch {
    return null;
  }
}

function getStoredComponents(): ScriptComponent[] | null {
  if (typeof window === "undefined") return null;
  const parsed = safeJsonParse<{ components: ScriptComponent[] }>(
    window.localStorage.getItem(COMPONENT_LIBRARY_STORAGE_KEY)
  );
  if (!parsed?.components || !Array.isArray(parsed.components)) return null;
  return parsed.components;
}

function setStoredComponents(components: ScriptComponent[]) {
  if (typeof window === "undefined") return;
  window.localStorage.setItem(
    COMPONENT_LIBRARY_STORAGE_KEY,
    JSON.stringify({ components })
  );
}

function ensureComponentSeeded() {
  const existing = getStoredComponents();
  if (existing && existing.length > 0) return;
  // Seed storage with the static library so users can edit/add immediately
  setStoredComponents(staticComponentsLibrary);
}

function normalizeComponent(component: ScriptComponent): ScriptComponent {
  return {
    ...component,
    title: component.title.trim(),
    body: component.body.trim(),
    tags: component.tags?.map(t => t.trim()).filter(Boolean),
  };
}

// Simulated API functions with static fallbacks
export const scriptsApi = {
  async getResearch(topic: string): Promise<{ success: boolean; data?: Research; error?: string }> {
    // Simulate API call delay
    await new Promise(resolve => setTimeout(resolve, 1500));
    
    try {
      // In real implementation, this would call the actual API
      // const response = await fetch(`${API_BASE_URL}/api/scripts/research`, { ... });
      // if (!response.ok) throw new Error('API request failed');
      // return { success: true, data: await response.json() };
      
      // Return static data for now
      return { 
        success: true, 
        data: {
          ...staticResearchData,
          // Customize summary based on topic
          executiveSummary: staticResearchData.executiveSummary.replace(
            'YouTube scripts',
            topic || 'your topic'
          )
        }
      };
    } catch (error) {
      // On error, return static data as fallback
      console.error('API Error, using static data:', error);
      return { success: true, data: staticResearchData };
    }
  },

  async getComponents(filters?: { type?: string; search?: string }): Promise<{ 
    success: boolean; 
    data?: ScriptComponent[]; 
    error?: string 
  }> {
    // Simulate API call delay
    await new Promise(resolve => setTimeout(resolve, 500));
    
    try {
      ensureComponentSeeded();

      // In real implementation, this would call the actual API
      // const response = await fetch(`${API_BASE_URL}/api/scripts/components?type=${filters?.type}&search=${filters?.search}`, { ... });
      let components = [...(getStoredComponents() ?? staticComponentsLibrary)];
      
      // Filter by type
      if (filters?.type && filters.type !== 'all') {
        components = components.filter(c => c.type === filters.type);
      }
      
      // Filter by search term
      if (filters?.search) {
        const searchLower = filters.search.toLowerCase();
        components = components.filter(c => 
          c.title.toLowerCase().includes(searchLower) ||
          c.body.toLowerCase().includes(searchLower) ||
          c.tags?.some(tag => tag.toLowerCase().includes(searchLower))
        );
      }
      
      return { success: true, data: components };
    } catch (error) {
      console.error('API Error, using static data:', error);
      return { success: true, data: staticComponentsLibrary };
    }
  },

  async getComponentById(id: string): Promise<{ success: boolean; data?: ScriptComponent; error?: string }> {
    await new Promise(resolve => setTimeout(resolve, 250));
    try {
      ensureComponentSeeded();
      const components = getStoredComponents() ?? staticComponentsLibrary;
      const found = components.find(c => c.id === id);
      if (!found) return { success: false, error: "Component not found" };
      return { success: true, data: found };
    } catch (error) {
      console.error("API Error:", error);
      return { success: false, error: "Failed to load component" };
    }
  },

  async createComponent(input: Omit<ScriptComponent, "id"> & { id?: string }): Promise<{ success: boolean; data?: ScriptComponent; error?: string }> {
    await new Promise(resolve => setTimeout(resolve, 500));
    try {
      ensureComponentSeeded();
      const components = getStoredComponents() ?? [...staticComponentsLibrary];
      const id = input.id?.trim() || `comp-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
      if (components.some(c => c.id === id)) {
        return { success: false, error: "A component with this id already exists" };
      }
      const created = normalizeComponent({
        id,
        type: input.type,
        title: input.title,
        body: input.body,
        tags: input.tags,
      });
      const next = [created, ...components];
      setStoredComponents(next);
      return { success: true, data: created };
    } catch (error) {
      console.error("API Error:", error);
      return { success: false, error: "Failed to create component" };
    }
  },

  async updateComponent(id: string, updates: Partial<Omit<ScriptComponent, "id">>): Promise<{ success: boolean; data?: ScriptComponent; error?: string }> {
    await new Promise(resolve => setTimeout(resolve, 500));
    try {
      ensureComponentSeeded();
      const components = getStoredComponents() ?? [...staticComponentsLibrary];
      const idx = components.findIndex(c => c.id === id);
      if (idx === -1) return { success: false, error: "Component not found" };
      const updated = normalizeComponent({
        ...components[idx],
        ...updates,
        id,
      });
      const next = [...components];
      next[idx] = updated;
      setStoredComponents(next);
      return { success: true, data: updated };
    } catch (error) {
      console.error("API Error:", error);
      return { success: false, error: "Failed to update component" };
    }
  },

  async deleteComponent(id: string): Promise<{ success: boolean; error?: string }> {
    await new Promise(resolve => setTimeout(resolve, 350));
    try {
      ensureComponentSeeded();
      const components = getStoredComponents() ?? [...staticComponentsLibrary];
      const next = components.filter(c => c.id !== id);
      setStoredComponents(next);
      return { success: true };
    } catch (error) {
      console.error("API Error:", error);
      return { success: false, error: "Failed to delete component" };
    }
  },

  async generateScript(params: {
    topic: string;
    research: Research;
    components: ScriptComponent[];
    scriptLength?: number;
  }): Promise<{ success: boolean; data?: GeneratedScript; error?: string }> {
    // Simulate API call delay for script generation
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    try {
      // In real implementation, this would call the actual API
      // const response = await fetch(`${API_BASE_URL}/api/scripts/generate`, { ... });
      
      // Generate a sample script based on selected components
      const hookComponent = params.components.find(c => c.type === 'hook');
      const ctaComponent = params.components.find(c => c.type === 'cta');
      const outroComponent = params.components.find(c => c.type === 'outro');
      const storyComponent = params.components.find(c => c.type === 'story');
      const transitionComponent = params.components.find(c => c.type === 'transition');
      
      let scriptContent = '';
      
      // Build script from components
      if (hookComponent) {
        scriptContent += hookComponent.body + '\n\n';
      }
      
      // Add main content based on research
      scriptContent += `When it comes to ${params.topic || 'this topic'}, the first thing you need to realize is that attention is the most valuable currency.
      
Whether you're writing an article, a video script, or a presentation, you have to grab your audience immediately with something unexpected or deeply valuable.

I've seen engagement double just by cutting the preamble and getting straight to the point. No fluff, no wasted words.

`;
      
      if (transitionComponent) {
        scriptContent += transitionComponent.body + '\n\n';
      }
      
      scriptContent += `Next, you need to structure your content with a clear logical flow that builds momentum.

Most people don't realize this, but the ${params.research.keyFacts[0]?.split(':')[0] || 'core facts'} can boost your credibility by a mind-blowing margin if presented correctly.

The research shows that ${params.research.executiveSummary.slice(0, 100)}... and this is just the beginning.

`;
      
      if (storyComponent) {
        scriptContent += storyComponent.body + '\n\n';
      }
      
      scriptContent += `When I started implementing these techniques, my results skyrocketed.

The secret is treating every single line like it's fighting for its life. If a component doesn't advance your message or provide value, cut it mercilessly.

Remember, your audience can feel when you're padding content. The best scripts feel effortless but are actually crafted with surgical precision.

`;
      
      if (ctaComponent) {
        scriptContent += ctaComponent.body + '\n\n';
      }
      
      scriptContent += `Try these techniques on your next video and watch what happens to your analytics.

Your audience will thank you for respecting their time and delivering pure value from start to finish.

`;
      
      if (outroComponent) {
        scriptContent += outroComponent.body;
      }
      
      const words = scriptContent.split(/\s+/).filter(w => w.length > 0);
      const wordCount = words.length;
      const readingTime = Math.ceil(wordCount / 150); // ~150 words per minute for video scripts
      
      const generatedScript: GeneratedScript = {
        id: `script-${Date.now()}`,
        title: params.topic,
        content: scriptContent.trim(),
        wordCount,
        readingTime,
        status: 'completed'
      };
      
      return { success: true, data: generatedScript };
    } catch (error) {
      console.error('API Error:', error);
      return { success: false, error: 'Failed to generate script' };
    }
  }
};

// Component type labels and icons
export const componentTypeConfig = {
  hook: { label: 'Hook', description: 'Grab attention in the first seconds' },
  cta: { label: 'CTA', description: 'Call to action for engagement' },
  outro: { label: 'Outro', description: 'Close your video effectively' },
  transition: { label: 'Transition', description: 'Smooth topic transitions' },
  story: { label: 'Story', description: 'Narrative and case studies' },
} as const;

export type ComponentType = keyof typeof componentTypeConfig;
