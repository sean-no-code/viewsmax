interface ScoreDisplayProps {
  score: number;
  showLabel?: boolean;
  size?: "sm" | "md" | "lg";
}

const ScoreDisplay = ({ score, showLabel = true, size = "md" }: ScoreDisplayProps) => {
  // Round the score to nearest integer
  const roundedScore = Math.round(score);
  const clampedScore = Math.max(0, Math.min(10, roundedScore));

  // Get text color for label
  const getLabelColor = (score: number): string => {
    if (score > 8) return "text-green-500";
    if (score >= 6) return "text-yellow-500";
    return "text-red-500";
  };

  // Format score to show whole numbers without decimals when appropriate
  const formatScore = (score: number): string => {
    const rounded = Math.round(score);
    return rounded === score ? `${rounded}` : score.toFixed(1);
  };

  // Size classes for text
  const textSizeClasses = {
    sm: "text-sm",
    md: "text-base",
    lg: "text-lg",
  };

  return (
    <span className={`${textSizeClasses[size]} font-semibold ${getLabelColor(clampedScore)}`}>
      {formatScore(score)}/10
    </span>
  );
};

export default ScoreDisplay;

