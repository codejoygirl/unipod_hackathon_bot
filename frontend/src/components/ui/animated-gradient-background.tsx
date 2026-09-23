"use client";

import { motion } from "motion/react";
import { useEffect, useRef } from "react";

interface AnimatedGradientBackgroundProps {
  /** Initial size of the radial gradient, defining the starting width. */
  startingGap?: number;
  /** Enables or disables the breathing animation effect. */
  Breathing?: boolean;
  /** Colours in the radial gradient, one per stop in `gradientStops`. */
  gradientColors?: string[];
  /** Percentage stop for each colour. Values range 0-100. */
  gradientStops?: number[];
  /** Speed of the breathing animation. Lower is slower. */
  animationSpeed?: number;
  /** How far the gradient breathes, in percentage points. */
  breathingRange?: number;
  containerStyle?: React.CSSProperties;
  containerClassName?: string;
  /** Extra top offset for the gradient origin. */
  topOffset?: number;
}

/**
 * AnimatedGradientBackground
 *
 * A radial gradient that slowly breathes, drawn straight to the DOM in a requestAnimationFrame
 * loop rather than through React state.
 *
 * Two departures from the original snippet: the import comes from `motion/react` (the `motion`
 * package is framer-motion's current name, so this avoids shipping both), and the default
 * colours are this app's palette rather than neon on near-black.
 *
 * The default ramp runs light in the centre out to colour at the rim — that is what reads as a
 * glow behind the content. Inverting it (colour centre, light edges) looks like a flat wash.
 */
const AnimatedGradientBackground: React.FC<AnimatedGradientBackgroundProps> = ({
  startingGap = 110,
  Breathing = false,
  gradientColors = [
    "var(--paper-raised)",
    "var(--paper)",
    "var(--accent-wash)",
    "var(--clay-wash)",
  ],
  gradientStops = [0, 30, 68, 100],
  animationSpeed = 0.02,
  breathingRange = 5,
  containerStyle = {},
  topOffset = 0,
  containerClassName = "",
}) => {
  if (gradientColors.length !== gradientStops.length) {
    throw new Error(
      `GradientColors and GradientStops must have the same length.
     Received gradientColors length: ${gradientColors.length},
     gradientStops length: ${gradientStops.length}`,
    );
  }

  const containerRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    let animationFrame: number;
    let width = startingGap;
    let directionWidth = 1;

    const animateGradient = () => {
      if (width >= startingGap + breathingRange) directionWidth = -1;
      if (width <= startingGap - breathingRange) directionWidth = 1;

      if (!Breathing) directionWidth = 0;
      width += directionWidth * animationSpeed;

      const gradientStopsString = gradientStops
        .map((stop, index) => `${gradientColors[index]} ${stop}%`)
        .join(", ");

      const gradient = `radial-gradient(${width}% ${width + topOffset}% at 50% 20%, ${gradientStopsString})`;

      if (containerRef.current) {
        containerRef.current.style.background = gradient;
      }

      animationFrame = requestAnimationFrame(animateGradient);
    };

    animationFrame = requestAnimationFrame(animateGradient);

    return () => cancelAnimationFrame(animationFrame);
  }, [
    startingGap,
    Breathing,
    gradientColors,
    gradientStops,
    animationSpeed,
    breathingRange,
    topOffset,
  ]);

  return (
    <motion.div
      key="animated-gradient-background"
      initial={{ opacity: 0, scale: 1.5 }}
      animate={{
        opacity: 1,
        scale: 1,
        transition: { duration: 2, ease: [0.25, 0.1, 0.25, 1] },
      }}
      className={`absolute inset-0 overflow-hidden ${containerClassName}`}
    >
      <div ref={containerRef} style={containerStyle} className="absolute inset-0" />
    </motion.div>
  );
};

export default AnimatedGradientBackground;
